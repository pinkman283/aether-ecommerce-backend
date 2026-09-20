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
use App\Models\OrderReturn;
use App\Models\CourierSettlement;
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
                        $coa = self::resolveChartOfAccount($code);
                        if (!$coa) {
                            throw new InvalidArgumentException("Chart of Account with code [{$code}] not found.");
                        }
                        $coaCache[$code] = $coa->id;
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

        if ($shipment->order) {
            $shipment->order->payments()->create([
                'payment_number' => \App\Models\OrderPayment::generatePaymentNumber(),
                'payment_method' => 'cash_on_delivery',
                'provider' => $shipment->provider ?: 'courier',
                'transaction_id' => (string) ($shipment->consignment_id ?: $shipment->tracking_code),
                'amount' => $totalCollected,
                'currency' => 'BDT',
                'status' => 'completed',
                'type' => 'collection',
                'collected_at' => now(),
                'notes' => "Courier remittance reconciled for consignment #{$shipment->consignment_id}",
            ]);
            $shipment->order->recalculatePaymentStatus();
        }

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
     * Post journal entry for an Order Return / Refund.
     * Debits: Account 4095 (Sales Returns & Refunds)
     * Credits: Cash/Bank/MFS/Store Credit (liability 2020) or Accounts Receivable (1100)
     */
    public static function postOrderReturn(OrderReturn $return, array $options = []): ?JournalEntry
    {
        // Avoid duplicate entry
        $existing = JournalEntry::where('reference_type', 'OrderReturn')
            ->where('reference_id', $return->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $order = $return->order;
        $refundAmount = round((float) $return->refund_amount, 2);
        $collectedByCourier = round((float) ($return->amount_collected_courier ?? 0), 2);
        $lines = [];

        if ($refundAmount > 0) {
            // Case 1: Cash/credit refund paid to customer (Customer Return of paid order)
            $lines[] = [
                'account_code' => '4095', // Sales Returns & Refunds (Contra-revenue)
                'debit' => $refundAmount,
                'credit' => 0.00,
                'memo' => "Sales return & refund for Return #{$return->return_number} (Order #{$order?->order_number})",
            ];

            $creditAccountCode = '1100'; // Default to A/R if customer hadn't paid
            $refundMethod = strtolower($return->refund_method ?? 'none');

            if ($refundMethod === 'store_credit') {
                $creditAccountCode = '2020'; // Customer Advances & Store Credit liability
            } elseif ($refundMethod === 'cash') {
                $creditAccountCode = '1010'; // Cash on Hand
            } elseif (in_array($refundMethod, ['bkash', 'nagad', 'rocket', 'mfs'])) {
                $creditAccountCode = '1030'; // Digital Wallets / MFS
            } elseif ($refundMethod === 'bank_transfer') {
                $creditAccountCode = '1020'; // Main Bank Account
            }

            $lines[] = [
                'account_code' => $creditAccountCode,
                'debit' => 0.00,
                'credit' => $refundAmount,
                'memo' => "Refund payout via {$refundMethod} for Return #{$return->return_number}",
            ];
        } else {
            // Case 2: Uncollected COD Return / RTO (Customer paid 0 or only delivery fee)
            // Reverse product revenue and clear uncollectible Accounts Receivable
            $productSubtotal = round((float) ($return->product_subtotal_snapshot ?: ($order?->subtotal ?? 0)), 2);
            $shippingCharge = round((float) ($return->shipping_charge_snapshot ?: ($order?->shipping_amount ?? 0)), 2);
            $orderTotal = round((float) ($return->order_total_snapshot ?: ($order?->total_amount ?? ($productSubtotal + $shippingCharge))), 2);

            // Amount to reverse from AR is order total minus whatever courier actually collected from customer
            $arToClear = max(0.00, round($orderTotal - $collectedByCourier, 2));

            $shippingChargeReverse = ($collectedByCourier <= 0 && $shippingCharge > 0) ? min($shippingCharge, $arToClear) : 0.00;
            $revenueToReverse = round($arToClear - $shippingChargeReverse, 2);

            if ($revenueToReverse > 0) {
                $lines[] = [
                    'account_code' => '4095', // Sales Returns & Allowances (Contra-revenue)
                    'debit' => $revenueToReverse,
                    'credit' => 0.00,
                    'memo' => "Reversal of unearned product revenue on RTO #{$return->return_number}",
                ];
            }

            if ($shippingChargeReverse > 0) {
                $lines[] = [
                    'account_code' => '4030', // Shipping & Delivery Income
                    'debit' => $shippingChargeReverse,
                    'credit' => 0.00,
                    'memo' => "Reversal of uncollected delivery fee on RTO #{$return->return_number}",
                ];
            }

            if ($arToClear > 0) {
                $lines[] = [
                    'account_code' => '1100', // Accounts Receivable
                    'debit' => 0.00,
                    'credit' => $arToClear,
                    'memo' => "Clear uncollectible A/R for refused parcel on RTO #{$return->return_number}",
                ];
            }
        }

        if (empty($lines)) {
            return null;
        }

        $header = [
            'entry_date' => now()->toDateString(),
            'reference_type' => 'OrderReturn',
            'reference_id' => $return->id,
            'reference_number' => $return->return_number,
            'narration' => "Return & RTO settlement journal for Return #{$return->return_number} (Order #{$order?->order_number})",
            'status' => 'posted',
        ];

        return self::postJournalEntry($header, $lines);
    }

    /**
     * Post reversing journal entry for an Order Cancellation / Void.
     * Inverts original debits and credits to cleanly clear A/R and Revenue without deleting history.
     */
    public static function postOrderCancellation(Order $order): ?JournalEntry
    {
        $existing = JournalEntry::where('reference_type', 'OrderCancellation')
            ->where('reference_id', $order->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $originalSale = JournalEntry::with('lines.account')
            ->where('reference_type', 'Order')
            ->where('reference_id', $order->id)
            ->first();

        if (!$originalSale) {
            return null;
        }

        $lines = [];
        foreach ($originalSale->lines as $line) {
            $debit = (float) $line->credit;
            $credit = (float) $line->debit;

            $lines[] = [
                'account_code' => $line->account?->code,
                'chart_of_account_id' => $line->chart_of_account_id,
                'debit' => $debit,
                'credit' => $credit,
                'memo' => "Reversal: " . $line->memo,
            ];
        }

        if (empty($lines)) {
            return null;
        }

        $header = [
            'entry_date' => now()->toDateString(),
            'reference_type' => 'OrderCancellation',
            'reference_id' => $order->id,
            'reference_number' => 'REV-' . $order->order_number,
            'narration' => "Complete cancellation and reversing entry for Order #{$order->order_number}",
            'status' => 'posted',
        ];

        return self::postJournalEntry($header, $lines);
    }

    /**
     * Post inventory cost reversal / write-off upon QC completion.
     * If restocked sellable: Debit 1200 (Merchandise Inventory), Credit 5010 (COGS - Online)
     * If damaged/loss: Debit 5030 (Inventory Shrinkage & Loss), Credit 5010 (COGS - Online)
     */
    public static function postInventoryReturnQc(OrderReturn $return, float $restockedCost, float $lossCost = 0.00): ?JournalEntry
    {
        $restockedCost = round($restockedCost, 2);
        $lossCost = round($lossCost, 2);

        if ($restockedCost <= 0 && $lossCost <= 0) {
            return null;
        }

        // Avoid duplicate entry
        $existing = JournalEntry::where('reference_type', 'OrderReturnQC')
            ->where('reference_id', $return->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $lines = [];

        // Restocked Sellable Goods: Put back into Merchandise Inventory and relieve COGS
        if ($restockedCost > 0) {
            $lines[] = [
                'account_code' => '1200', // Merchandise Inventory
                'debit' => $restockedCost,
                'credit' => 0.00,
                'memo' => "Restock returned sellable goods for Return #{$return->return_number}",
            ];
            $lines[] = [
                'account_code' => '5010', // COGS - Online
                'debit' => 0.00,
                'credit' => $restockedCost,
                'memo' => "COGS relief for restocked items from Return #{$return->return_number}",
            ];
        }

        // Damaged / Write-off Goods: Reclassify from COGS to Shrinkage/Damage Loss
        if ($lossCost > 0) {
            $lines[] = [
                'account_code' => '5030', // Inventory Shrinkage & Loss
                'debit' => $lossCost,
                'credit' => 0.00,
                'memo' => "Transit damage / write-off loss on Return #{$return->return_number}",
            ];
            $lines[] = [
                'account_code' => '5010', // COGS - Online
                'debit' => 0.00,
                'credit' => $lossCost,
                'memo' => "Reclassify transit loss out of ordinary COGS for Return #{$return->return_number}",
            ];
        }

        if (empty($lines)) {
            return null;
        }

        $header = [
            'entry_date' => now()->toDateString(),
            'reference_type' => 'OrderReturnQC',
            'reference_id' => $return->id,
            'reference_number' => "QC-{$return->return_number}",
            'narration' => "Inventory QC restock & write-off valuation for Return #{$return->return_number}",
            'status' => 'posted',
        ];

        return self::postJournalEntry($header, $lines);
    }

    /**
     * Post journal entry for a direct Order Refund from Admin.
     * Accrual Accounting:
     * Debits: Account 4095 (Sales Returns & Refunds - Contra Revenue)
     * Credits: Cash/Bank/MFS/Store Credit (Account 1010, 1020, 1030, or 2020)
     * Optional Inventory Restock:
     * Debits: Account 1200 (Merchandise Inventory)
     * Credits: Account 5010/5020 (COGS)
     */
    public static function postOrderDirectRefund(
        Order $order,
        float $refundAmount,
        string $refundMethod = 'cash',
        float $restockedCost = 0.00,
        ?User $actor = null,
        ?string $reason = null
    ): ?JournalEntry {
        $refundAmount = round($refundAmount, 2);
        if ($refundAmount <= 0) {
            return null;
        }

        $lines = [];

        // 1. Debit Sales Returns & Refunds (Contra-Revenue)
        $lines[] = [
            'account_code' => '4095', // Sales Returns & Refunds
            'debit' => $refundAmount,
            'credit' => 0.00,
            'memo' => "Direct refund on Order #{$order->order_number}: " . ($reason ?: 'Customer refund'),
        ];

        // 2. Credit the funding account
        $method = strtolower($refundMethod);
        $creditAccountCode = '1010'; // Cash on Hand default
        if ($method === 'store_credit') {
            $creditAccountCode = '2020'; // Customer Advances & Store Credit
        } elseif (in_array($method, ['bkash', 'nagad', 'rocket', 'mfs'])) {
            $creditAccountCode = '1030'; // Digital Wallets
        } elseif (in_array($method, ['bank', 'bank_transfer', 'card'])) {
            $creditAccountCode = '1020'; // Bank Account
        }

        $lines[] = [
            'account_code' => $creditAccountCode,
            'debit' => 0.00,
            'credit' => $refundAmount,
            'memo' => "Refund payout via {$method} for Order #{$order->order_number}",
        ];

        // 3. If restocked cost > 0, restore inventory & reverse COGS
        $restockedCost = round($restockedCost, 2);
        if ($restockedCost > 0) {
            $cogsCode = ($order->order_source === 'pos') ? '5020' : '5010';
            $lines[] = [
                'account_code' => '1200', // Merchandise Inventory
                'debit' => $restockedCost,
                'credit' => 0.00,
                'memo' => "Restocked inventory cost for refunded Order #{$order->order_number}",
            ];
            $lines[] = [
                'account_code' => $cogsCode, // COGS
                'debit' => 0.00,
                'credit' => $restockedCost,
                'memo' => "COGS relief for refunded Order #{$order->order_number}",
            ];
        }

        $refSuffix = time();
        $header = [
            'entry_date' => now()->toDateString(),
            'reference_type' => 'OrderRefund',
            'reference_id' => $order->id,
            'reference_number' => "REF-{$order->order_number}-{$refSuffix}",
            'narration' => "Direct refund of ৳{$refundAmount} for Order #{$order->order_number}" . ($reason ? " ({$reason})" : ""),
            'created_by_user_id' => $actor?->id,
            'status' => 'posted',
        ];

        return self::postJournalEntry($header, $lines);
    }

    /**
     * Post courier settlement batch reconciliation.
     * Debits:
     *   - Bank Account (1020 or linked) for actual payout received
     *   - Courier & Logistics Expense (6040) for total delivery & return fee deductions
     *   - Variance Expense (6090) if underpaid
     * Credits:
     *   - Accounts Receivable (1100) for total COD collected
     *   - Other Income (4030 / 6090) if overpaid
     */
    public static function postCourierSettlementBatch(CourierSettlement $settlement, ?int $bankAccountId = null): ?JournalEntry
    {
        $totalCollected = round((float) $settlement->total_cod_collected, 2);
        $deliveryFees = round((float) $settlement->delivery_fees, 2);
        $returnFees = round((float) $settlement->return_fees, 2);
        $otherDeductions = round((float) $settlement->other_deductions, 2);
        $actualPayout = round((float) $settlement->actual_payout, 2);
        $variance = round((float) $settlement->variance, 2);

        $totalDeductions = round($deliveryFees + $returnFees + $otherDeductions, 2);

        // Determine destination bank account code
        $bankAccountCode = '1020'; // Default Main Bank Account
        if ($bankAccountId) {
            $bank = BankAccount::find($bankAccountId);
            if ($bank && $bank->chartOfAccount) {
                $bankAccountCode = $bank->chartOfAccount->account_code;
            }
        }

        $lines = [];

        // 1. Debit Bank Account for net actual cash received
        if ($actualPayout > 0) {
            $lines[] = [
                'account_code' => $bankAccountCode,
                'debit' => $actualPayout,
                'credit' => 0.00,
                'memo' => "Net courier remittance deposit for batch #{$settlement->settlement_number} ({$settlement->provider})",
            ];
        }

        // 2. Debit Courier & Logistics Expense (6040)
        if ($totalDeductions > 0) {
            $lines[] = [
                'account_code' => '6040', // Courier & Logistics Expense
                'debit' => $totalDeductions,
                'credit' => 0.00,
                'memo' => "Logistics & return deductions for {$settlement->provider} batch #{$settlement->settlement_number}",
            ];
        }

        // 3. Credit Accounts Receivable (1100) for total COD collected
        if ($totalCollected > 0) {
            $lines[] = [
                'account_code' => '1100', // Accounts Receivable
                'debit' => 0.00,
                'credit' => $totalCollected,
                'memo' => "Clear Accounts Receivable on COD batch #{$settlement->settlement_number}",
            ];
        }

        // 4. Handle Variance
        // Variance = Expected Payout - Actual Payout
        // If variance > 0: Courier short-paid. Debit Expense / A/R dispute.
        // If variance < 0: Courier overpaid. Credit Misc Income.
        if ($variance > 0.01) {
            $lines[] = [
                'account_code' => '6090', // General & Misc Expenses (Settlement Variance)
                'debit' => $variance,
                'credit' => 0.00,
                'memo' => "Settlement shortfall/discrepancy on batch #{$settlement->settlement_number}",
            ];
        } elseif ($variance < -0.01) {
            $lines[] = [
                'account_code' => '6090',
                'debit' => 0.00,
                'credit' => abs($variance),
                'memo' => "Settlement overpayment credit on batch #{$settlement->settlement_number}",
            ];
        }

        if (empty($lines)) {
            return null;
        }

        $header = [
            'entry_date' => $settlement->settlement_date ? Carbon::parse($settlement->settlement_date)->toDateString() : now()->toDateString(),
            'reference_type' => 'CourierSettlement',
            'reference_id' => $settlement->id,
            'reference_number' => $settlement->settlement_number,
            'narration' => "Reconciled courier COD remittance batch #{$settlement->settlement_number} from {$settlement->provider}",
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

    /**
     * Resolve ChartOfAccount by account code with resilient auto-healing.
     */
    public static function resolveChartOfAccount(string $code): ?ChartOfAccount
    {
        $coa = ChartOfAccount::where('account_code', $code)->first();
        if ($coa) {
            return $coa;
        }

        // 1. Attempt running the seeder to populate system accounts
        try {
            if (class_exists(\Database\Seeders\ChartOfAccountsSeeder::class)) {
                (new \Database\Seeders\ChartOfAccountsSeeder())->run();
                $coa = ChartOfAccount::where('account_code', $code)->first();
                if ($coa) {
                    return $coa;
                }
            }
        } catch (\Throwable $e) {
            // Silently continue to fallback dictionary
        }

        // 2. Direct fallback definition map for core system accounts
        $coreSystemAccounts = [
            '1010' => ['account_name' => 'Cash on Hand', 'account_type' => 'asset', 'is_system' => true],
            '1020' => ['account_name' => 'Main Business Bank Account', 'account_type' => 'asset', 'is_system' => true],
            '1030' => ['account_name' => 'Digital Wallets & MFS', 'account_type' => 'asset', 'is_system' => true],
            '1100' => ['account_name' => 'Accounts Receivable (A/R)', 'account_type' => 'asset', 'is_system' => true],
            '1200' => ['account_name' => 'Merchandise Inventory', 'account_type' => 'asset', 'is_system' => true],
            '1500' => ['account_name' => 'Equipment & Fixed Assets', 'account_type' => 'asset', 'is_system' => false],
            '2010' => ['account_name' => 'Accounts Payable (A/P)', 'account_type' => 'liability', 'is_system' => true],
            '2020' => ['account_name' => 'Customer Advances & Store Credit', 'account_type' => 'liability', 'is_system' => true],
            '2030' => ['account_name' => 'Sales Tax / VAT Payable', 'account_type' => 'liability', 'is_system' => true],
            '3010' => ['account_name' => "Owner's Capital", 'account_type' => 'equity', 'is_system' => true],
            '3020' => ['account_name' => 'Retained Earnings', 'account_type' => 'equity', 'is_system' => true],
            '4010' => ['account_name' => 'Online Sales Revenue', 'account_type' => 'revenue', 'is_system' => true],
            '4020' => ['account_name' => 'POS & In-Store Sales Revenue', 'account_type' => 'revenue', 'is_system' => true],
            '4030' => ['account_name' => 'Shipping & Delivery Income', 'account_type' => 'revenue', 'is_system' => true],
            '4090' => ['account_name' => 'Sales Discounts & Promotions', 'account_type' => 'revenue', 'is_system' => true],
            '4095' => ['account_name' => 'Sales Returns & Refunds', 'account_type' => 'revenue', 'is_system' => true],
            '5010' => ['account_name' => 'Cost of Goods Sold - Online', 'account_type' => 'cogs', 'is_system' => true],
            '5020' => ['account_name' => 'Cost of Goods Sold - POS', 'account_type' => 'cogs', 'is_system' => true],
            '5030' => ['account_name' => 'Inventory Shrinkage & Loss', 'account_type' => 'cogs', 'is_system' => false],
            '6040' => ['account_name' => 'Courier & Logistics Expense', 'account_type' => 'expense', 'is_system' => false],
            '6070' => ['account_name' => 'Payment Gateway & Banking Fees', 'account_type' => 'expense', 'is_system' => false],
        ];

        if (isset($coreSystemAccounts[$code])) {
            $data = $coreSystemAccounts[$code];
            return ChartOfAccount::firstOrCreate(
                ['account_code' => $code],
                [
                    'account_name' => $data['account_name'],
                    'account_type' => $data['account_type'],
                    'is_system' => $data['is_system'] ?? true,
                    'is_active' => true,
                ]
            );
        }

        return null;
    }
}

