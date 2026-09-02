<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Cache;

/**
 * SlidingWindowRateLimiter
 *
 * Implements a high-precision Sliding Window Log rate limiting algorithm.
 * 
 * Unlike fixed decay-window rate limiting, Sliding Window Log:
 * 1. Eliminates boundary burst vulnerabilities (100% mathematically exact window tracking).
 * 2. Computes the exact second when the oldest request drops out of the active window.
 * 3. Works seamlessly with all cache backends (database, file, redis, memcached, array).
 */
class SlidingWindowRateLimiter
{
    protected string $prefix = 'sw_rate_limit:';

    /**
     * Evaluate and record an attempt against the sliding window.
     *
     * @param string $key Unique identifier for the rate limit target.
     * @param int $maxAttempts Maximum allowed requests within the window.
     * @param int $windowSeconds Rolling window duration in seconds (default: 60).
     * @param float|null $customNow Optional override timestamp for testing.
     * @return array{allowed: bool, limit: int, remaining: int, retry_after: int, current: int, reset_at: int}
     */
    public function attempt(string $key, int $maxAttempts, int $windowSeconds = 60, ?float $customNow = null): array
    {
        $now = $customNow ?? microtime(true);
        $cacheKey = $this->prefix . $key;
        $cutoff = $now - $windowSeconds;

        // Retrieve active timestamps log
        $raw = Cache::get($cacheKey, []);
        $timestamps = is_array($raw) ? $raw : [];

        // Purge timestamps outside the rolling window
        $activeTimestamps = [];
        foreach ($timestamps as $ts) {
            if (is_numeric($ts) && (float)$ts > $cutoff) {
                $activeTimestamps[] = (float)$ts;
            }
        }
        sort($activeTimestamps);

        $currentCount = count($activeTimestamps);

        if ($currentCount >= $maxAttempts) {
            // Rate limit exceeded
            $oldestInWindow = $activeTimestamps[0] ?? $now;
            $retryAfter = max(1, (int) ceil(($oldestInWindow + $windowSeconds) - $now));
            $resetAt = (int) ceil(end($activeTimestamps) + $windowSeconds);

            // Update pruned timestamps in cache
            $ttl = $windowSeconds + 120;
            Cache::put($cacheKey, $activeTimestamps, $ttl);

            return [
                'allowed' => false,
                'limit' => $maxAttempts,
                'remaining' => 0,
                'retry_after' => $retryAfter,
                'current' => $currentCount,
                'reset_at' => $resetAt,
            ];
        }

        // Attempt allowed: record current timestamp
        $activeTimestamps[] = $now;
        $newCount = count($activeTimestamps);
        $ttl = $windowSeconds + 120;
        Cache::put($cacheKey, $activeTimestamps, $ttl);

        $remaining = max(0, $maxAttempts - $newCount);
        $resetAt = (int) ceil($now + $windowSeconds);

        return [
            'allowed' => true,
            'limit' => $maxAttempts,
            'remaining' => $remaining,
            'retry_after' => 0,
            'current' => $newCount,
            'reset_at' => $resetAt,
        ];
    }

    /**
     * Check if too many attempts have been made without recording a new attempt.
     */
    public function tooManyAttempts(string $key, int $maxAttempts, int $windowSeconds = 60, ?float $customNow = null): bool
    {
        $now = $customNow ?? microtime(true);
        $cacheKey = $this->prefix . $key;
        $cutoff = $now - $windowSeconds;

        $raw = Cache::get($cacheKey, []);
        $timestamps = is_array($raw) ? $raw : [];

        $activeCount = 0;
        foreach ($timestamps as $ts) {
            if (is_numeric($ts) && (float)$ts > $cutoff) {
                $activeCount++;
            }
        }

        return $activeCount >= $maxAttempts;
    }

    /**
     * Get the number of seconds until the earliest slot opens.
     */
    public function availableIn(string $key, int $windowSeconds = 60, ?float $customNow = null): int
    {
        $now = $customNow ?? microtime(true);
        $cacheKey = $this->prefix . $key;
        $cutoff = $now - $windowSeconds;

        $raw = Cache::get($cacheKey, []);
        $timestamps = is_array($raw) ? $raw : [];

        $activeTimestamps = [];
        foreach ($timestamps as $ts) {
            if (is_numeric($ts) && (float)$ts > $cutoff) {
                $activeTimestamps[] = (float)$ts;
            }
        }

        if (empty($activeTimestamps)) {
            return 0;
        }

        sort($activeTimestamps);
        $oldest = $activeTimestamps[0];

        return max(0, (int) ceil(($oldest + $windowSeconds) - $now));
    }

    /**
     * Get the remaining allowed requests for a given key.
     */
    public function remaining(string $key, int $maxAttempts, int $windowSeconds = 60, ?float $customNow = null): int
    {
        $now = $customNow ?? microtime(true);
        $cacheKey = $this->prefix . $key;
        $cutoff = $now - $windowSeconds;

        $raw = Cache::get($cacheKey, []);
        $timestamps = is_array($raw) ? $raw : [];

        $activeCount = 0;
        foreach ($timestamps as $ts) {
            if (is_numeric($ts) && (float)$ts > $cutoff) {
                $activeCount++;
            }
        }

        return max(0, $maxAttempts - $activeCount);
    }

    /**
     * Clear all recorded timestamps for a key.
     */
    public function clear(string $key): void
    {
        Cache::forget($this->prefix . $key);
    }

    /**
     * Alias for clear().
     */
    public function reset(string $key): void
    {
        $this->clear($key);
    }

    /**
     * Retrieve all active timestamps in the window for auditing/diagnostics.
     *
     * @return array<float>
     */
    public function getTimestamps(string $key, int $windowSeconds = 60, ?float $customNow = null): array
    {
        $now = $customNow ?? microtime(true);
        $cacheKey = $this->prefix . $key;
        $cutoff = $now - $windowSeconds;

        $raw = Cache::get($cacheKey, []);
        $timestamps = is_array($raw) ? $raw : [];

        $active = [];
        foreach ($timestamps as $ts) {
            if (is_numeric($ts) && (float)$ts > $cutoff) {
                $active[] = (float)$ts;
            }
        }
        sort($active);
        return $active;
    }
}
