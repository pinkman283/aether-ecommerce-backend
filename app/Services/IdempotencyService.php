<?php

namespace App\Services;

use App\Models\IdempotencyKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class IdempotencyService
{
    /**
     * Maximum seconds to wait for a concurrent in-flight request before timing out.
     */
    protected const LOCK_WAIT_SECONDS = 6;

    /**
     * Execute an action idempotently keyed by the given key.
     *
     * @param string|null $key Unique idempotency key (from X-Idempotency-Key header)
     * @param array $payload Normalized request payload for fingerprinting
     * @param callable $callback The business logic that produces a JsonResponse
     * @param int|null $userId Authenticated user ID if any
     * @return JsonResponse
     * @throws Throwable
     */
    public static function run(?string $key, array $payload, callable $callback, ?int $userId = null): JsonResponse
    {
        // If no idempotency key was supplied by client, simply execute callback directly
        if (empty($key)) {
            return $callback();
        }

        $cleanKey = trim($key);
        $requestHash = hash('sha256', json_encode($payload));

        // 1. Check if record already exists outside of long transaction
        $existing = IdempotencyKey::where('key', $cleanKey)->first();

        if ($existing) {
            // If already completed, return stored response immediately!
            if ($existing->status === 'completed' && !empty($existing->response_body)) {
                $decoded = json_decode($existing->response_body, true);
                $headers = ['X-Idempotency-Replayed' => 'true'];
                return response()->json($decoded, $existing->response_code ?: 200, $headers);
            }

            // If processing, wait briefly for concurrent execution to finish
            if ($existing->status === 'processing') {
                $startTime = microtime(true);
                while ((microtime(true) - $startTime) < self::LOCK_WAIT_SECONDS) {
                    usleep(250000); // 250ms
                    $refreshed = IdempotencyKey::where('key', $cleanKey)->first();
                    if ($refreshed && $refreshed->status === 'completed' && !empty($refreshed->response_body)) {
                        $decoded = json_decode($refreshed->response_body, true);
                        return response()->json($decoded, $refreshed->response_code ?: 200, ['X-Idempotency-Replayed' => 'true']);
                    }
                    if (!$refreshed || $refreshed->status === 'failed') {
                        break;
                    }
                }

                $latest = IdempotencyKey::where('key', $cleanKey)->first();
                if ($latest && $latest->status === 'completed' && !empty($latest->response_body)) {
                    $decoded = json_decode($latest->response_body, true);
                    return response()->json($decoded, $latest->response_code ?: 200, ['X-Idempotency-Replayed' => 'true']);
                }

                throw new ConflictHttpException("A checkout request with this idempotency key is currently processing. Please do not retry concurrently.");
            }

            if ($existing->status === 'failed') {
                $existing->delete();
            }
        }

        // 2. Concurrency-safe reservation using unique DB constraint
        $record = null;
        try {
            $record = DB::transaction(function () use ($cleanKey, $requestHash, $userId) {
                return IdempotencyKey::create([
                    'key' => $cleanKey,
                    'request_hash' => $requestHash,
                    'status' => 'processing',
                    'user_id' => $userId,
                    'locked_at' => now(),
                ]);
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Another thread won the race to insert the key! Fetch and replay or wait
            $winner = IdempotencyKey::where('key', $cleanKey)->first();
            if ($winner && $winner->status === 'completed' && !empty($winner->response_body)) {
                $decoded = json_decode($winner->response_body, true);
                return response()->json($decoded, $winner->response_code ?: 200, ['X-Idempotency-Replayed' => 'true']);
            }
            throw new ConflictHttpException("A concurrent order creation request is already underway with this key.");
        }

        // 3. Execute the actual checkout block
        try {
            /** @var JsonResponse $response */
            $response = $callback();

            // Extract order ID if present in response
            $responseData = $response->getData(true);
            $orderId = $responseData['order']['id'] ?? ($responseData['id'] ?? null);

            $record->update([
                'status' => 'completed',
                'response_code' => $response->getStatusCode(),
                'response_body' => json_encode($responseData),
                'order_id' => $orderId,
            ]);

            return $response;
        } catch (Throwable $e) {
            // On failure, delete reservation so client can retry with corrected data
            if ($record) {
                try {
                    $record->delete();
                } catch (Throwable $delEx) {
                    // Ignore deletion error
                }
            }
            throw $e;
        }
    }
}
