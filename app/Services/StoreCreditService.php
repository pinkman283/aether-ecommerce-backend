<?php

namespace App\Services;

use App\Models\Order;
use App\Models\StoreCreditAccount;
use App\Models\StoreCreditTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StoreCreditService
{
    /**
     * Get or initialize a customer's store credit account.
     */
    public static function getOrCreateAccount(User $user): StoreCreditAccount
    {
        return StoreCreditAccount::firstOrCreate(
            ['user_id' => $user->id],
            [
                'balance' => 0.00,
                'total_credited' => 0.00,
                'total_debited' => 0.00,
                'is_frozen' => false,
            ]
        );
    }

    /**
     * Get current store credit balance for a customer.
     */
    public static function getBalance(User $user): float
    {
        $account = self::getOrCreateAccount($user);
        return $account->is_frozen ? 0.00 : (float) $account->balance;
    }

    /**
     * Issue credit to a customer's wallet.
     */
    public static function credit(
        User $user,
        float $amount,
        string $reason,
        string $type = 'credit',
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?User $staffUser = null,
        ?\DateTimeInterface $expiresAt = null
    ): StoreCreditTransaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException("Credit amount must be greater than zero.");
        }

        return DB::transaction(function () use ($user, $amount, $reason, $type, $referenceType, $referenceId, $staffUser, $expiresAt) {
            $account = StoreCreditAccount::where('user_id', $user->id)->lockForUpdate()->first();
            if (!$account) {
                $account = StoreCreditAccount::create([
                    'user_id' => $user->id,
                    'balance' => 0.00,
                    'total_credited' => 0.00,
                    'total_debited' => 0.00,
                    'is_frozen' => false,
                ]);
            }

            $newBalance = round((float) $account->balance + $amount, 2);
            $account->balance = $newBalance;
            $account->total_credited = round((float) $account->total_credited + $amount, 2);
            $account->save();

            return StoreCreditTransaction::create([
                'account_id' => $account->id,
                'user_id' => $user->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reason' => $reason,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'created_by_user_id' => $staffUser?->id,
                'expires_at' => $expiresAt,
            ]);
        });
    }

    /**
     * Debit store credit from a customer's wallet.
     */
    public static function debit(
        User $user,
        float $amount,
        string $reason,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?User $staffUser = null
    ): StoreCreditTransaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException("Debit amount must be greater than zero.");
        }

        return DB::transaction(function () use ($user, $amount, $reason, $referenceType, $referenceId, $staffUser) {
            $account = StoreCreditAccount::where('user_id', $user->id)->lockForUpdate()->first();
            if (!$account || $account->balance < $amount) {
                $avail = $account ? $account->balance : 0.00;
                throw new InvalidArgumentException("Insufficient store credit balance. Available: \${$avail}, Requested: \${$amount}");
            }

            if ($account->is_frozen) {
                throw new InvalidArgumentException("Store credit wallet is currently frozen. Please contact support.");
            }

            $newBalance = round((float) $account->balance - $amount, 2);
            $account->balance = $newBalance;
            $account->total_debited = round((float) $account->total_debited + $amount, 2);
            $account->save();

            return StoreCreditTransaction::create([
                'account_id' => $account->id,
                'user_id' => $user->id,
                'type' => 'debit',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reason' => $reason,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'created_by_user_id' => $staffUser?->id,
            ]);
        });
    }

    /**
     * Authoritatively applies store credit to an order during checkout.
     * Returns the actual applied store credit amount.
     */
    public static function applyToOrder(Order $order, float $requestedAmount, User $user): float
    {
        if ($requestedAmount <= 0) {
            return 0.00;
        }

        $account = StoreCreditAccount::where('user_id', $user->id)->lockForUpdate()->first();
        if (!$account || $account->is_frozen || $account->balance <= 0) {
            return 0.00;
        }

        // The maximum credit that can be applied is the order remaining payable balance
        $currentPayable = (float) $order->total_amount;
        $maxUsable = min((float) $account->balance, $currentPayable, $requestedAmount);
        if ($maxUsable <= 0) {
            return 0.00;
        }

        $newBalance = round((float) $account->balance - $maxUsable, 2);
        $account->balance = $newBalance;
        $account->total_debited = round((float) $account->total_debited + $maxUsable, 2);
        $account->save();

        StoreCreditTransaction::create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'type' => 'debit',
            'amount' => $maxUsable,
            'balance_after' => $newBalance,
            'reason' => "Applied to Order #{$order->order_number}",
            'reference_type' => 'Order',
            'reference_id' => (string) $order->id,
            'created_by_user_id' => $user->id,
        ]);

        $order->store_credit_amount = $maxUsable;
        $order->total_amount = max(0.00, round($currentPayable - $maxUsable, 2));
        $order->save();

        return $maxUsable;
    }

    /**
     * Restore store credit if an order that used store credit is refunded.
     */
    public static function refundOrderCredit(Order $order, ?string $reason = null): void
    {
        $creditAmount = (float) ($order->store_credit_amount ?? 0);
        if ($creditAmount <= 0 || !$order->user_id) {
            return;
        }

        $user = User::find($order->user_id);
        if (!$user) {
            return;
        }

        self::credit(
            $user,
            $creditAmount,
            $reason ?: "Store credit refunded for Order #{$order->order_number}",
            'refund',
            'Order',
            (string) $order->id
        );
    }
}
