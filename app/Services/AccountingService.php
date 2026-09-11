<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\GoodsReceipt;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Order;
use App\Models\SupplierPayment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AccountingService
{
    /**
     * Post a balanced journal entry with multiple debit/credit lines.
     *
     * @param array{
     *     entry_date: string|Carbon,
     *     narration: string,
     *     reference_type?: string|null,
     *     reference_id?: int|string|null,
     *     reference_number?: string|null,
     *     created_by_user_id?: int|null,
     *     status?: string
     * } $header
     * @param array<int, array{
     *     account_code?: string,
     *     chart_of_account_id?: int,
     *     debit: float,
     *     credit: float,
     *     memo?: string|null
     * }> $lines
     */
    public static function postJournalEntry(array $header, array $lines): JournalEntry
    {
        return DB::transaction(function () use ($header, $lines) {
            $totalDebit = 0.00;
            $totalCredit = 0.00;
            $processedLines = [];

            // Cache COA lookups
            $coaCache = [];

            foreach ($lines as $line) {
                $debit = round((float) ($line['debit'] ?? 0), 2);
                $credit = round((float) ($line['credit'] ?? 0), 2);

                if ($debit <= 0 && $credit <= 0) {
                    continue; // Skip zero-value lines
                }

                $coaId = $line['chart_of_account_id'] ?? null;
                if (!$coaId && !empty($line['account_code'])) {
                    $code = $line['account_code'];
                    if (!isset($coaCache[$code])) {
                        $coa = ChartOfAccount::where('account_code', $code)->first();
                        if (!$coa) {
                            throw new InvalidArgumentException("Chart of Account with code [{$code}] not found.");
                        }
                        $coaCache[$code] = $coa->id;
                    }
                    $coaId = $coaCache[$code];
                }

                if (!$coaId) {
                    throw new InvalidArgumentException("Each journal entry line must specify a valid chart_of_account_id or account_code.");
                }

                $totalDebit += $debit;
                $totalCredit += $credit;

                $processedLines[] = [
                    'chart_of_account_id' => $coaId,
                    'debit' => $debit,
                    'credit' => $credit,
                    'memo' => $line['memo'] ?? null,
                ];
            }

            if (empty($processedLines)) {
                throw new InvalidArgumentException("A journal entry must have at least one non-zero line.");
            }

            // Enforce double-entry debit == credit equality
            if (abs($totalDebit - $totalCredit) > 0.02) {
                throw new InvalidArgumentException(
                    "Journal entry is out of balance! Total Debits ($totalDebit) must equal Total Credits ($totalCredit)."
                );
            }

            // Micro-rounding adjustment if difference is within 0.02
            if (abs($totalDebit - $totalCredit) > 0.0001 && count($processedLines) > 1) {
                $diff = round($totalDebit - $totalCredit, 2);
                // Adjust on the first credit line or last line
                $lastIndex = count($processedLines) - 1;
                if ($diff > 0) {
                    $processedLines[$lastIndex]['credit'] += $diff;
                } else {
                    $processedLines[$lastIndex]['debit'] += abs($diff);
                }
            }

            // Generate unique entry number
            $entryDate = Carbon::parse($header['entry_date'] ?? now());
            $datePrefix = 'JE-' . $entryDate->format('Ymd');
            $countToday = JournalEntry::where('entry_number', 'LIKE', "{$datePrefix}-%")->count();
            $entryNumber = sprintf('%s-%04d', $datePrefix, $countToday + 1);

            $journalEntry = JournalEntry::create([
                'entry_number' => $entryNumber,
                'entry_date' => $entryDate->toDateString(),
                'reference_type' => $header['reference_type'] ?? null,
                'reference_id' => $header['reference_id'] ?? null,
                'reference_number' => $header['reference_number'] ?? null,
                'narration' => $header['narration'],
                'status' => $header['status'] ?? 'posted',
                'created_by_user_id' => $header['created_by_user_id'] ?? null,
            ]);

            foreach ($processedLines as $pLine) {
                $journalEntry->lines()->create($pLine);
            }

            // Update associated bank accounts if touched
            self::syncBankBalances();

            return $journalEntry->load('lines.account');
        });
    }

    /**
     * Post journal entry for an Order sale.
     * Accrual Accounting:
     * Debits: Cash/Bank (if paid) OR Accounts Receivable (if unpaid) + Sales Discounts
     * Credits: Sales Revenue + Shipping Income + Sales Tax Payable
     * + COGS & Inventory entry if cogs_amount > 0.
     */
    public static function postOrderSale(Order $order): ?JournalEntry
    {
        // Don't post cancelled orders
        if ($order->order_status === 'cancelled') {
            return null;
        }

        // Avoid duplicate entry
        $existing = JournalEntry::where('reference_type', 'Order')
            ->where('reference_id', $order->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $orderDate = $order->created_at ?: now();
        $isPaid = $order->payment_status === 'paid';
        $isPos = $order->order_source === 'pos';

        $totalAmount = round((float) $order->total_amount, 2);
        $subtotal = round((float) $order->subtotal, 2);
        $shipping = round((float) $order->shipping_amount, 2);
        $tax = round((float) $order->tax_amount, 2);
        $discount = round((float) $order->discount_amount, 2);
        $storeCredit = round((float) ($order->store_credit_amount ?? 0), 2);

        // Determine payment account code
        $paymentAccountCode = '1100'; // Accounts Receivable by default
        if ($isPaid) {
            $method = strtolower($order->payment_method ?? '');
            if ($isPos || in_array($method, ['cash', 'cash_on_delivery', 'cod'])) {
                $paymentAccountCode = '1010'; // Cash on Hand
            } elseif (in_array($method, ['bkash', 'nagad', 'rocket', 'upay', 'mfs'])) {
                $paymentAccountCode = '1030'; // Digital Wallets / MFS
            } else {
                $paymentAccountCode = '1020'; // Main Bank Account
            }
        }

        $revenueAccountCode = $isPos ? '4020' : '4010'; // POS Sales vs Online Sales

        $lines = [];

        // 1. Debit Cash/Bank or A/R for amount paid/owed
        if ($totalAmount > 0) {
            $lines[] = [
                'account_code' => $paymentAccountCode,
                'debit' => $totalAmount,
                'credit' => 0.00,
                'memo' => "Order #{$order->order_number} settlement/receivable",
            ];
        }

        // 2. Debit Store Credit Liability (Account 2020: Customer Advances & Store Credit)
        if ($storeCredit > 0) {
            $lines[] = [
                'account_code' => '2020',
                'debit' => $storeCredit,
                'credit' => 0.00,
                'memo' => "Store credit redemption on Order #{$order->order_number}",
            ];
        }

        // 3. Debit Discount (Account 4090: Sales Discounts & Promotions)
        if ($discount > 0) {
            $lines[] = [
                'account_code' => '4090', // Sales Discounts & Promotions
                'debit' => $discount,
                'credit' => 0.00,
                'memo' => "Promotional discount on Order #{$order->order_number}",
            ];
        }

        // 3. Credit Revenue (Subtotal)
        if ($subtotal > 0) {
            $lines[] = [
                'account_code' => $revenueAccountCode,
                'debit' => 0.00,
                'credit' => $subtotal,
                'memo' => "Product revenue for Order #{$order->order_number}",
            ];
        }

        // 4. Credit Shipping Income (if any)
        if ($shipping > 0) {
            $lines[] = [
                'account_code' => '4030', // Shipping & Delivery Income
                'debit' => 0.00,
                'credit' => $shipping,
                'memo' => "Shipping fee collected on Order #{$order->order_number}",
            ];
        }

        // 5. Credit Tax Payable (if any)
        if ($tax > 0) {
            $lines[] = [
                'account_code' => '2030', // Sales Tax / VAT Payable
                'debit' => 0.00,
                'credit' => $tax,
                'memo' => "Sales tax collected on Order #{$order->order_number}",
            ];
        }

        // 6. COGS & Inventory Relief (if cogs_amount exists)
        $cogs = round((float) ($order->cogs_amount ?? 0), 2);
        if ($cogs > 0) {
            $cogsAccountCode = $isPos ? '5020' : '5010';
            $lines[] = [
                'account_code' => $cogsAccountCode,
                'debit' => $cogs,
                'credit' => 0.00,
                'memo' => "Cost of Goods Sold for Order #{$order->order_number}",
            ];
            $lines[] = [
                'account_code' => '1200', // Merchandise Inventory
                'debit' => 0.00,
                'credit' => $cogs,
                'memo' => "Inventory relief for Order #{$order->order_number}",
            ];
        }

        $header = [
            'entry_date' => $orderDate,
            'reference_type' => 'Order',
            'reference_id' => $order->id,
            'reference_number' => $order->order_number,
            'narration' => "Sale recognized for Order #{$order->order_number} ({$order->customer_name})",
            'created_by_user_id' => $order->cashier_user_id ?: $order->user_id,
            'status' => 'posted',
        ];

        return self::postJournalEntry($header, $lines);
    }

    /**
     * Post journal entry for an operational Expense.
     * Debit OPEX account, Credit Cash/Bank.
     */
    public static function postExpense(Expense $expense): ?JournalEntry
    {
        $existing = JournalEntry::where('reference_type', 'Expense')
            ->where('reference_id', $expense->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $amount = round((float) $expense->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        // Map expense category / title to appropriate OPEX COA
        $catName = strtolower($expense->category?->name ?? $expense->title ?? '');
        $opexCode = '6090'; // Default Misc Expense

        if (str_contains($catName, 'rent') || str_contains($catName, 'facility') || str_contains($catName, 'office')) {
            $opexCode = '6010';
        } elseif (str_contains($catName, 'salar') || str_contains($catName, 'wage') || str_contains($catName, 'payroll') || str_contains($catName, 'bonus')) {
            $opexCode = '6020';
        } elseif (str_contains($catName, 'market') || str_contains($catName, 'ad') || str_contains($catName, 'promo')) {
            $opexCode = '6030';
        } elseif (str_contains($catName, 'courier') || str_contains($catName, 'deliver') || str_contains($catName, 'ship') || str_contains($catName, 'freight')) {
            $opexCode = '6040';
        } elseif (str_contains($catName, 'utilit') || str_contains($catName, 'electric') || str_contains($catName, 'power') || str_contains($catName, 'net')) {
            $opexCode = '6050';
        } elseif (str_contains($catName, 'soft') || str_contains($catName, 'cloud') || str_contains($catName, 'host') || str_contains($catName, 'saas')) {
            $opexCode = '6060';
        } elseif (str_contains($catName, 'fee') || str_contains($catName, 'gateway') || str_contains($catName, 'stripe') || str_contains($catName, 'charge')) {
            $opexCode = '6070';
        }

        // Determine credit account (Payment method)
        $method = strtolower($expense->payment_method ?? '');
        $creditCode = '1020'; // Bank by default
        if ($method === 'cash') {
            $creditCode = '1010';
        } elseif (in_array($method, ['bkash', 'nagad', 'mfs'])) {
            $creditCode = '1030';
        } elseif ($expense->status === 'pending') {
            $creditCode = '2010'; // Accounts Payable if unpaid
        }

        $lines = [
            [
                'account_code' => $opexCode,
                'debit' => $amount,
                'credit' => 0.00,
                'memo' => "Expense: {$expense->title}",
            ],
            [
                'account_code' => $creditCode,
                'debit' => 0.00,
                'credit' => $amount,
                'memo' => "Payment for Expense #{$expense->expense_number}",
            ],
        ];

        $header = [
            'entry_date' => $expense->expense_date ?: $expense->created_at ?: now(),
            'reference_type' => 'Expense',
            'reference_id' => $expense->id,
            'reference_number' => $expense->expense_number,
            'narration' => "Operating Expense: {$expense->title} ({$expense->payee_name})",
            'created_by_user_id' => $expense->created_by_user_id,
            'status' => 'posted',
        ];

        return self::postJournalEntry($header, $lines);
    }

    /**
     * Post journal entry for Goods Receipt from Supplier.
     * Debit Merchandise Inventory (1200), Credit Accounts Payable (2010).
     */
    public static function postGoodsReceipt(GoodsReceipt $receipt): ?JournalEntry
    {
        $existing = JournalEntry::where('reference_type', 'GoodsReceipt')
            ->where('reference_id', $receipt->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $receiptTotal = 0.00;
        foreach ($receipt->items as $item) {
            $receiptTotal += round((float) ($item->quantity_received * $item->unit_cost), 2);
        }

        if ($receiptTotal <= 0) {
            return null;
        }

        $lines = [
            [
                'account_code' => '1200', // Merchandise Inventory
                'debit' => $receiptTotal,
                'credit' => 0.00,
                'memo' => "Inventory received via GRN #{$receipt->receipt_number}",
            ],
            [
                'account_code' => '2010', // Accounts Payable
                'debit' => 0.00,
                'credit' => $receiptTotal,
                'memo' => "Payable to {$receipt->vendor?->name} for PO #{$receipt->purchaseOrder?->po_number}",
            ],
        ];

        $header = [
            'entry_date' => $receipt->received_date ?: $receipt->created_at ?: now(),
            'reference_type' => 'GoodsReceipt',
            'reference_id' => $receipt->id,
            'reference_number' => $receipt->receipt_number,
            'narration' => "Procurement Inventory Received from {$receipt->vendor?->name} (PO #{$receipt->purchaseOrder?->po_number})",
            'created_by_user_id' => $receipt->received_by_user_id,
            'status' => 'posted',
        ];

        return self::postJournalEntry($header, $lines);
    }

    /**
     * Post Customer Payment (A/R Collection).
     * Debit Cash/Bank, Credit Accounts Receivable (1100).
     */
    public static function postCustomerPayment(CustomerPayment $payment): JournalEntry
    {
        $amount = round((float) $payment->amount, 2);
        $bankAccount = $payment->bankAccount;

        $debitCoaId = $bankAccount?->chart_of_account_id;
        $debitCode = null;
        if (!$debitCoaId) {
            $method = strtolower($payment->payment_method ?? '');
            $debitCode = in_array($method, ['cash']) ? '1010' : (in_array($method, ['bkash', 'nagad', 'mfs']) ? '1030' : '1020');
        }

        $lines = [
            [
                'chart_of_account_id' => $debitCoaId,
                'account_code' => $debitCode,
                'debit' => $amount,
                'credit' => 0.00,
                'memo' => "Customer payment received for Order #{$payment->order?->order_number}",
            ],
            [
                'account_code' => '1100', // Accounts Receivable
                'debit' => 0.00,
                'credit' => $amount,
                'memo' => "Clear A/R for Order #{$payment->order?->order_number}",
            ],
        ];

        $header = [
            'entry_date' => $payment->payment_date ?: now(),
            'reference_type' => 'CustomerPayment',
            'reference_id' => $payment->id,
            'reference_number' => $payment->payment_number,
            'narration' => "Customer payment received: {$payment->payment_number} for Order #{$payment->order?->order_number}",
            'created_by_user_id' => $payment->created_by_user_id,
            'status' => 'posted',
        ];

        $entry = self::postJournalEntry($header, $lines);

        // Check if order balance is now fully cleared
        $order = $payment->order;
        if ($order) {
            $totalPaid = (float) CustomerPayment::where('order_id', $order->id)->sum('amount');
            if ($order->payment_status === 'paid') {
                $totalPaid += (float) $order->total_amount;
            }
            if ($totalPaid >= (float) $order->total_amount) {
                $order->update(['payment_status' => 'paid']);
            }
        }

        return $entry;
    }

    /**
     * Post Supplier Payment (A/P Settlement).
     * Debit Accounts Payable (2010), Credit Cash/Bank.
     */
    public static function postSupplierPayment(SupplierPayment $payment): JournalEntry
    {
        $amount = round((float) $payment->amount, 2);
        $bankAccount = $payment->bankAccount;

        $creditCoaId = $bankAccount?->chart_of_account_id;
        $creditCode = null;
        if (!$creditCoaId) {
            $method = strtolower($payment->payment_method ?? '');
            $creditCode = in_array($method, ['cash']) ? '1010' : (in_array($method, ['bkash', 'nagad', 'mfs']) ? '1030' : '1020');
        }

        $lines = [
            [
                'account_code' => '2010', // Accounts Payable
                'debit' => $amount,
                'credit' => 0.00,
                'memo' => "Settle payable to {$payment->vendor?->name}",
            ],
            [
                'chart_of_account_id' => $creditCoaId,
                'account_code' => $creditCode,
                'debit' => 0.00,
                'credit' => $amount,
                'memo' => "Payment voucher {$payment->payment_number} to {$payment->vendor?->name}",
            ],
        ];

        $header = [
            'entry_date' => $payment->payment_date ?: now(),
            'reference_type' => 'SupplierPayment',
            'reference_id' => $payment->id,
            'reference_number' => $payment->payment_number,
            'narration' => "Supplier payment voucher {$payment->payment_number} to {$payment->vendor?->name}",
            'created_by_user_id' => $payment->created_by_user_id,
            'status' => 'posted',
        ];

        return self::postJournalEntry($header, $lines);
    }

    /**
     * Post internal bank/cash fund transfer.
     * Debit destination account, Credit source account.
     */
    public static function postFundTransfer(
        BankAccount $fromAccount,
        BankAccount $toAccount,
        float $amount,
        string $referenceNumber = '',
        string $notes = '',
        ?User $actor = null
    ): JournalEntry {
        if ($fromAccount->id === $toAccount->id) {
            throw new InvalidArgumentException("Source and destination accounts must be different.");
        }

        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException("Transfer amount must be greater than zero.");
        }

        $lines = [
            [
                'chart_of_account_id' => $toAccount->chart_of_account_id,
                'debit' => $amount,
                'credit' => 0.00,
                'memo' => "Fund transfer from {$fromAccount->account_name} to {$toAccount->account_name}",
            ],
            [
                'chart_of_account_id' => $fromAccount->chart_of_account_id,
                'debit' => 0.00,
                'credit' => $amount,
                'memo' => "Fund transfer to {$toAccount->account_name}",
            ],
        ];

        $header = [
            'entry_date' => now()->toDateString(),
            'reference_type' => 'Transfer',
            'reference_number' => $referenceNumber ?: ('TRF-' . time()),
            'narration' => "Internal transfer: {$amount} from [{$fromAccount->account_name}] to [{$toAccount->account_name}]. {$notes}",
            'created_by_user_id' => $actor?->id,
            'status' => 'posted',
        ];

        return self::postJournalEntry($header, $lines);
    }

    /**
     * Post courier booking expense entry (Account 6040: Courier & Logistics Expense)
     */
    public static function postCourierBooking(\App\Models\Shipment $shipment): ?JournalEntry
    {
        $fee = round((float) $shipment->courier_charge, 2);
        if ($fee <= 0) {
            return null;
        }

        // Prevent duplicate entry
        $existing = JournalEntry::where('reference_type', 'Shipment')
            ->where('reference_id', $shipment->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $lines = [
            [
                'account_code' => '6040', // Courier & Logistics Expense
                'debit' => $fee,
                'credit' => 0.00,
                'memo' => "{$shipment->provider} delivery fee for Consignment #{$shipment->consignment_id}",
            ],
            [
                'account_code' => '2040', // Accrued Operational Liabilities (payable to courier)
                'debit' => 0.00,
                'credit' => $fee,
                'memo' => "Accrued payable to {$shipment->provider}",
            ],
        ];

        $header = [
            'entry_date' => now()->toDateString(),
            'reference_type' => 'Shipment',
            'reference_id' => $shipment->id,
            'reference_number' => 'SHP-' . $shipment->id,
            'narration' => "Logistics expense for Order #{$shipment->order?->order_number} via {$shipment->provider} (CID: {$shipment->consignment_id})",
            'status' => 'posted',
        ];

        return self::postJournalEntry($header, $lines);
    }

    /**
     * Post courier remittance settlement (Bank deposit minus courier deductions)
     */
    public static function postCourierRemittance(
        \App\Models\Shipment $shipment,
        float $netRemitted,
        float $courierFeeDeducted = 0.00
    ): ?JournalEntry {
        $totalCollected = round($netRemitted + $courierFeeDeducted, 2);
        if ($totalCollected <= 0) {
            return null;
        }

        $lines = [];

        // 1. Debit Main Bank Account for net cash deposited
        if ($netRemitted > 0) {
            $lines[] = [
                'account_code' => '1020', // Main Business Bank Account
                'debit' => round($netRemitted, 2),
                'credit' => 0.00,
                'memo' => "Net remittance from {$shipment->provider} for CID #{$shipment->consignment_id}",
            ];
        }

        // 2. Debit Courier Expense for fees deducted
        if ($courierFeeDeducted > 0) {
            $lines[] = [
                'account_code' => '6040', // Courier & Logistics Expense
                'debit' => round($courierFeeDeducted, 2),
                'credit' => 0.00,
                'memo' => "{$shipment->provider} service fee deducted on settlement",
            ];
        }

        // 3. Credit Accounts Receivable (clearing the customer/courier debt)
        $lines[] = [
            'account_code' => '1100', // Accounts Receivable
            'debit' => 0.00,
            'credit' => $totalCollected,
            'memo' => "COD settlement for Order #{$shipment->order?->order_number}",
        ];

        $header = [
            'entry_date' => now()->toDateString(),
            'reference_type' => 'CourierRemittance',
            'reference_id' => $shipment->id,
            'reference_number' => 'RMT-' . $shipment->id . '-' . time(),
            'narration' => "Courier remittance reconciled: Order #{$shipment->order?->order_number} via {$shipment->provider}",
            'status' => 'posted',
        ];

        return self::postJournalEntry($header, $lines);
    }

    /**
     * Recalculate and synchronize all BankAccount current_balances from opening_balance
     * plus posted debit/credit lines on their linked ChartOfAccount.
     */
    public static function syncBankBalances(): void
    {
        $accounts = BankAccount::whereNotNull('chart_of_account_id')->get();
        foreach ($accounts as $bank) {
            $lines = JournalEntryLine::where('chart_of_account_id', $bank->chart_of_account_id)
                ->whereHas('journalEntry', function ($q) {
                    $q->where('status', 'posted');
                })
                ->selectRaw('SUM(debit) as total_debit, SUM(credit) as total_credit')
                ->first();

            $debit = (float) ($lines->total_debit ?? 0);
            $credit = (float) ($lines->total_credit ?? 0);

            // For Asset accounts (Cash & Bank), Balance = Opening + Debit - Credit
            $computedBalance = round((float) $bank->opening_balance + ($debit - $credit), 2);
            $bank->update(['current_balance' => $computedBalance]);
        }
    }
}

