<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\CustomerPayment;
use App\Models\GoodsReceipt;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\SupplierPayment;
use App\Models\Vendor;
use App\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminAccountingController extends Controller
{
    /**
     * Helper to resolve standardized date ranges.
     */
    private function getDateRange(Request $request): array
    {
        $period = $request->input('period', 'this_month');
        $now = Carbon::now();

        switch ($period) {
            case 'today':
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                break;
            case 'yesterday':
                $start = $now->copy()->subDay()->startOfDay();
                $end = $now->copy()->subDay()->endOfDay();
                break;
            case 'this_week':
                $start = $now->copy()->startOfWeek();
                $end = $now->copy()->endOfWeek();
                break;
            case 'last_week':
                $start = $now->copy()->subWeek()->startOfWeek();
                $end = $now->copy()->subWeek()->endOfWeek();
                break;
            case 'this_month':
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfMonth();
                break;
            case 'last_month':
                $start = $now->copy()->subMonth()->startOfMonth();
                $end = $now->copy()->subMonth()->endOfMonth();
                break;
            case 'this_quarter':
                $start = $now->copy()->firstOfQuarter();
                $end = $now->copy()->lastOfQuarter();
                break;
            case 'this_year':
                $start = $now->copy()->startOfYear();
                $end = $now->copy()->endOfYear();
                break;
            case 'custom':
                $start = $request->filled('date_from') ? Carbon::parse($request->input('date_from'))->startOfDay() : $now->copy()->startOfMonth();
                $end = $request->filled('date_to') ? Carbon::parse($request->input('date_to'))->endOfDay() : $now->copy()->endOfDay();
                break;
            case 'all':
            default:
                $start = Carbon::parse('2020-01-01')->startOfDay();
                $end = $now->copy()->endOfDay();
                break;
        }

        return [$start, $end];
    }

    /**
     * 1. Overview & Financial Dashboard
     */
    public function overview(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'accounting.view', 'finance.reports_view');
        [$startDate, $endDate] = $this->getDateRange($request);

        // Subquery for journal entry lines within date range
        $periodLineQuery = JournalEntryLine::whereHas('journalEntry', function ($q) use ($startDate, $endDate) {
            $q->where('status', 'posted')
              ->whereBetween('entry_date', [$startDate->toDateString(), $endDate->toDateString()]);
        });

        // 1. Revenue: 4000-series
        $revenueAccounts = ChartOfAccount::where('account_type', 'revenue')->pluck('id');
        $grossSalesLines = (clone $periodLineQuery)
            ->whereIn('chart_of_account_id', $revenueAccounts)
            ->selectRaw('SUM(credit) as total_credit, SUM(debit) as total_debit')
            ->first();

        $grossRevenue = (float) ($grossSalesLines->total_credit ?? 0);
        $revenueDiscounts = (float) ($grossSalesLines->total_debit ?? 0);
        $netRevenue = $grossRevenue - $revenueDiscounts;

        // 2. COGS: 5000-series
        $cogsAccounts = ChartOfAccount::where('account_type', 'cogs')->pluck('id');
        $cogsLines = (clone $periodLineQuery)
            ->whereIn('chart_of_account_id', $cogsAccounts)
            ->selectRaw('SUM(debit) - SUM(credit) as net_cogs')
            ->first();
        $totalCogs = max(0, (float) ($cogsLines->net_cogs ?? 0));

        // 3. Gross Profit & Margin
        $grossProfit = $netRevenue - $totalCogs;
        $grossMargin = $netRevenue > 0 ? round(($grossProfit / $netRevenue) * 100, 2) : 0;

        // 4. OPEX: 6000-series
        $expenseAccounts = ChartOfAccount::where('account_type', 'expense')->pluck('id');
        $expenseLines = (clone $periodLineQuery)
            ->whereIn('chart_of_account_id', $expenseAccounts)
            ->selectRaw('SUM(debit) - SUM(credit) as net_expense')
            ->first();
        $totalOpex = max(0, (float) ($expenseLines->net_expense ?? 0));

        // 5. Net Operating Profit & Margin
        $netProfit = $grossProfit - $totalOpex;
        $netMargin = $netRevenue > 0 ? round(($netProfit / $netRevenue) * 100, 2) : 0;

        // 6. Balance Sheet Position (Cumulative as of endDate)
        $cumulativeQuery = JournalEntryLine::whereHas('journalEntry', function ($q) use ($endDate) {
            $q->where('status', 'posted')
              ->where('entry_date', '<=', $endDate->toDateString());
        });

        // Accounts Receivable (1100)
        $arCoa = ChartOfAccount::where('account_code', '1100')->first();
        $arBal = 0.00;
        if ($arCoa) {
            $arLine = (clone $cumulativeQuery)->where('chart_of_account_id', $arCoa->id)
                ->selectRaw('SUM(debit) - SUM(credit) as bal')->first();
            $arBal = max(0, (float) ($arLine->bal ?? 0));
        }

        // Accounts Payable (2010)
        $apCoa = ChartOfAccount::where('account_code', '2010')->first();
        $apBal = 0.00;
        if ($apCoa) {
            $apLine = (clone $cumulativeQuery)->where('chart_of_account_id', $apCoa->id)
                ->selectRaw('SUM(credit) - SUM(debit) as bal')->first();
            $apBal = max(0, (float) ($apLine->bal ?? 0));
        }

        // Cash & Liquid Assets (1010, 1020, 1030)
        $liquidCoas = ChartOfAccount::whereIn('account_code', ['1010', '1020', '1030'])->pluck('id');
        $liquidLine = (clone $cumulativeQuery)->whereIn('chart_of_account_id', $liquidCoas)
            ->selectRaw('SUM(debit) - SUM(credit) as bal')->first();
        $totalLiquidCash = max(0, (float) ($liquidLine->bal ?? 0));

        // Merchandise Inventory (1200)
        $invCoa = ChartOfAccount::where('account_code', '1200')->first();
        $inventoryAssetVal = 0.00;
        if ($invCoa) {
            $invLine = (clone $cumulativeQuery)->where('chart_of_account_id', $invCoa->id)
                ->selectRaw('SUM(debit) - SUM(credit) as bal')->first();
            $inventoryAssetVal = max(0, (float) ($invLine->bal ?? 0));
        }

        // 7. Expense Breakdown by Category
        $expenseBreakdown = (clone $periodLineQuery)
            ->whereIn('chart_of_account_id', $expenseAccounts)
            ->join('chart_of_accounts', 'journal_entry_lines.chart_of_account_id', '=', 'chart_of_accounts.id')
            ->select('chart_of_accounts.account_name', 'chart_of_accounts.account_code')
            ->selectRaw('SUM(journal_entry_lines.debit - journal_entry_lines.credit) as total_amount')
            ->groupBy('chart_of_accounts.account_name', 'chart_of_accounts.account_code')
            ->orderByDesc('total_amount')
            ->get();

        // 7b. Revenue Breakdown by Channel / Source (4010, 4020, 4030, 4090, 4095)
        $onlineRevLine = (clone $periodLineQuery)->whereHas('account', fn($q) => $q->where('account_code', '4010'))->selectRaw('SUM(credit - debit) as amt')->first();
        $posRevLine = (clone $periodLineQuery)->whereHas('account', fn($q) => $q->where('account_code', '4020'))->selectRaw('SUM(credit - debit) as amt')->first();
        $shippingRevLine = (clone $periodLineQuery)->whereHas('account', fn($q) => $q->where('account_code', '4030'))->selectRaw('SUM(credit - debit) as amt')->first();
        $discountLine = (clone $periodLineQuery)->whereHas('account', fn($q) => $q->where('account_code', '4090'))->selectRaw('SUM(debit - credit) as amt')->first();
        $returnLine = (clone $periodLineQuery)->whereHas('account', fn($q) => $q->where('account_code', '4095'))->selectRaw('SUM(debit - credit) as amt')->first();

        $revenueBreakdown = [
            'online_sales' => max(0, round((float) ($onlineRevLine->amt ?? 0), 2)),
            'pos_sales' => max(0, round((float) ($posRevLine->amt ?? 0), 2)),
            'shipping_income' => max(0, round((float) ($shippingRevLine->amt ?? 0), 2)),
            'discounts' => max(0, round((float) ($discountLine->amt ?? 0), 2)),
            'returns' => max(0, round((float) ($returnLine->amt ?? 0), 2)),
        ];

        // 7c. Receivables & Payables Snapshot in Period
        $collectedInPeriod = (float) CustomerPayment::whereBetween('payment_date', [$startDate->toDateString(), $endDate->toDateString()])->sum('amount');
        $disbursedInPeriod = (float) SupplierPayment::whereBetween('payment_date', [$startDate->toDateString(), $endDate->toDateString()])->sum('amount');

        // 8. Trend data (Grouped by date)
        $dailyTrends = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts', 'journal_entry_lines.chart_of_account_id', '=', 'chart_of_accounts.id')
            ->where('journal_entries.status', 'posted')
            ->whereBetween('journal_entries.entry_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->select('journal_entries.entry_date')
            ->selectRaw("SUM(CASE WHEN chart_of_accounts.account_type = 'revenue' THEN (journal_entry_lines.credit - journal_entry_lines.debit) ELSE 0 END) as daily_revenue")
            ->selectRaw("SUM(CASE WHEN chart_of_accounts.account_type = 'cogs' THEN (journal_entry_lines.debit - journal_entry_lines.credit) ELSE 0 END) as daily_cogs")
            ->selectRaw("SUM(CASE WHEN chart_of_accounts.account_type = 'expense' THEN (journal_entry_lines.debit - journal_entry_lines.credit) ELSE 0 END) as daily_expense")
            ->groupBy('journal_entries.entry_date')
            ->orderBy('journal_entries.entry_date', 'asc')
            ->get()
            ->map(function ($row) {
                $rev = (float) $row->daily_revenue;
                $cogs = (float) $row->daily_cogs;
                $exp = (float) $row->daily_expense;
                $gross = $rev - $cogs;
                $net = $gross - $exp;
                return [
                    'date' => $row->entry_date,
                    'revenue' => round($rev, 2),
                    'cogs' => round($cogs, 2),
                    'expense' => round($exp, 2),
                    'total_cost' => round($cogs + $exp, 2),
                    'gross_profit' => round($gross, 2),
                    'net_profit' => round($net, 2),
                ];
            });

        // 9. Bank Accounts snapshot
        $bankAccounts = BankAccount::with('chartOfAccount')->where('is_active', true)->get();

        // 10. Recent Journal Entries (8)
        $recentEntries = JournalEntry::with('lines.account')
            ->orderBy('entry_date', 'desc')
            ->orderBy('id', 'desc')
            ->limit(8)
            ->get();

        return response()->json([
            'success' => true,
            'period' => [
                'from' => $startDate->toDateString(),
                'to' => $endDate->toDateString(),
            ],
            'kpis' => [
                'gross_revenue' => round($grossRevenue, 2),
                'discounts' => round($revenueDiscounts, 2),
                'net_revenue' => round($netRevenue, 2),
                'cogs' => round($totalCogs, 2),
                'gross_profit' => round($grossProfit, 2),
                'gross_margin_percent' => $grossMargin,
                'operating_expenses' => round($totalOpex, 2),
                'net_profit' => round($netProfit, 2),
                'net_margin_percent' => $netMargin,
                'accounts_receivable' => round($arBal, 2),
                'accounts_payable' => round($apBal, 2),
                'liquid_cash_and_bank' => round($totalLiquidCash, 2),
                'inventory_valuation' => round($inventoryAssetVal, 2),
                'collected_in_period' => round($collectedInPeriod, 2),
                'disbursed_in_period' => round($disbursedInPeriod, 2),
            ],
            'revenue_breakdown' => $revenueBreakdown,
            'expense_breakdown' => $expenseBreakdown,
            'daily_trends' => $dailyTrends,
            'bank_accounts' => $bankAccounts,
            'recent_entries' => $recentEntries,
        ]);
    }

    /**
     * 2. Chart of Accounts Tree & Balances
     */
    public function accounts(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'accounting.view');

        $query = ChartOfAccount::query();

        if ($request->filled('account_type')) {
            $query->where('account_type', $request->input('account_type'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $accounts = $query->orderBy('account_code', 'asc')->get();

        // Compute balances for all accounts
        $accountsWithBalance = $accounts->map(function ($account) {
            return [
                'id' => $account->id,
                'account_code' => $account->account_code,
                'account_name' => $account->account_name,
                'account_type' => $account->account_type,
                'parent_id' => $account->parent_id,
                'is_system' => $account->is_system,
                'is_active' => $account->is_active,
                'description' => $account->description,
                'balance' => round($account->balance, 2),
                'normal_balance' => $account->isNormalDebit() ? 'Debit' : 'Credit',
            ];
        });

        return response()->json([
            'success' => true,
            'accounts' => $accountsWithBalance,
        ]);
    }

    /**
     * 3. Create Custom Chart of Account
     */
    public function storeAccount(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'accounting.manage');

        $validated = $request->validate([
            'account_code' => 'required|string|max:20|unique:chart_of_accounts,account_code',
            'account_name' => 'required|string|max:150',
            'account_type' => 'required|in:asset,liability,equity,revenue,cogs,expense',
            'parent_id' => 'nullable|exists:chart_of_accounts,id',
            'description' => 'nullable|string|max:255',
        ]);

        $account = ChartOfAccount::create([
            'account_code' => $validated['account_code'],
            'account_name' => $validated['account_name'],
            'account_type' => $validated['account_type'],
            'parent_id' => $validated['parent_id'] ?? null,
            'description' => $validated['description'] ?? null,
            'is_system' => false,
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Account created successfully.',
            'account' => $account,
        ], 201);
    }

    /**
     * 4. General Ledger with Filterable Entries
     */
    public function ledger(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'accounting.view');

        $query = JournalEntry::with(['lines.account', 'createdByUser']);

        // Search
        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('entry_number', 'LIKE', "%{$search}%")
                  ->orWhere('narration', 'LIKE', "%{$search}%")
                  ->orWhere('reference_number', 'LIKE', "%{$search}%")
                  ->orWhereHas('lines', function ($lineQuery) use ($search) {
                      $lineQuery->where('memo', 'LIKE', "%{$search}%");
                  });
            });
        }

        // Account filter
        if ($request->filled('chart_of_account_id')) {
            $coaId = (int) $request->input('chart_of_account_id');
            $query->whereHas('lines', function ($lineQ) use ($coaId) {
                $lineQ->where('chart_of_account_id', $coaId);
            });
        } elseif ($request->filled('account_code')) {
            $code = $request->input('account_code');
            $query->whereHas('lines.account', function ($lineQ) use ($code) {
                $lineQ->where('account_code', $code);
            });
        }

        // Date range
        if ($request->filled('date_from')) {
            $query->where('entry_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('entry_date', '<=', $request->input('date_to'));
        }

        // Status
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = min(100, max(10, (int) $request->input('per_page', 20)));
        $entries = $query->orderBy('entry_date', 'desc')->orderBy('id', 'desc')->paginate($perPage);

        // Calculate total debits and credits across entire filtered set
        $unpaginatedQuery = clone $query;
        $unpaginatedQuery->getQuery()->orders = null;
        $filteredEntryIds = $unpaginatedQuery->pluck('id');
        $totalDebit = (float) JournalEntryLine::whereIn('journal_entry_id', $filteredEntryIds)->sum('debit');
        $totalCredit = (float) JournalEntryLine::whereIn('journal_entry_id', $filteredEntryIds)->sum('credit');

        return response()->json([
            'success' => true,
            'entries' => $entries,
            'summary' => [
                'total_debit' => round($totalDebit, 2),
                'total_credit' => round($totalCredit, 2),
                'is_balanced' => round($totalDebit, 2) === round($totalCredit, 2),
            ],
        ]);
    }

    /**
     * 5. Post Manual Journal Entry
     */
    public function createJournalEntry(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'accounting.manage');

        $validated = $request->validate([
            'entry_date' => 'required|date',
            'narration' => 'required|string|max:500',
            'reference_number' => 'nullable|string|max:100',
            'lines' => 'required|array|min:2',
            'lines.*.chart_of_account_id' => 'nullable|exists:chart_of_accounts,id',
            'lines.*.account_code' => 'nullable|string|exists:chart_of_accounts,account_code',
            'lines.*.debit' => 'required|numeric|min:0',
            'lines.*.credit' => 'required|numeric|min:0',
            'lines.*.memo' => 'nullable|string|max:255',
        ]);

        try {
            $header = [
                'entry_date' => $validated['entry_date'],
                'narration' => $validated['narration'],
                'reference_type' => 'Manual',
                'reference_number' => $validated['reference_number'] ?? null,
                'created_by_user_id' => $request->user()?->id,
                'status' => 'posted',
            ];

            $journalEntry = AccountingService::postJournalEntry($header, $validated['lines']);

            return response()->json([
                'success' => true,
                'message' => "Journal Entry {$journalEntry->entry_number} posted successfully.",
                'entry' => $journalEntry,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * 6. Accounts Receivable (Customer Dues & Aging)
     */
    public function receivables(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'accounting.view');

        // Orders with pending or unpaid payment status
        $orders = Order::where('order_status', '!=', 'cancelled')
            ->where('payment_status', '!=', 'paid')
            ->with(['user', 'items'])
            ->orderBy('created_at', 'desc')
            ->get();

        $now = Carbon::now();
        $aging = [
            'current' => 0.00,  // 0 - 30 days
            'days_31_60' => 0.00,
            'days_61_90' => 0.00,
            'days_90_plus' => 0.00,
            'total_receivable' => 0.00,
        ];

        $unpaidOrders = $orders->map(function ($order) use ($now, &$aging) {
            $paidAmount = (float) CustomerPayment::where('order_id', $order->id)->sum('amount');
            $dueAmount = max(0, round((float) $order->total_amount - $paidAmount, 2));

            $daysOverdue = (int) $order->created_at->diffInDays($now);

            if ($daysOverdue <= 30) {
                $aging['current'] += $dueAmount;
            } elseif ($daysOverdue <= 60) {
                $aging['days_31_60'] += $dueAmount;
            } elseif ($daysOverdue <= 90) {
                $aging['days_61_90'] += $dueAmount;
            } else {
                $aging['days_90_plus'] += $dueAmount;
            }
            $aging['total_receivable'] += $dueAmount;

            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'customer_name' => $order->customer_name,
                'customer_email' => $order->customer_email,
                'customer_phone' => $order->customer_phone,
                'order_date' => $order->created_at->toDateString(),
                'days_open' => $daysOverdue,
                'total_amount' => round((float) $order->total_amount, 2),
                'paid_amount' => round($paidAmount, 2),
                'due_amount' => $dueAmount,
                'payment_status' => $order->payment_status,
                'order_status' => $order->order_status,
            ];
        });

        // Filter out fully cleared orders if any
        $activeDues = $unpaidOrders->filter(fn($o) => $o['due_amount'] > 0)->values();

        $totalInvoiced = round($unpaidOrders->sum('total_amount'), 2);
        $totalCollected = round($unpaidOrders->sum('paid_amount'), 2);
        $totalDue = round($aging['total_receivable'], 2);
        $collectionRate = ($totalInvoiced > 0) ? round(($totalCollected / $totalInvoiced) * 100, 1) : 0;
        $dueSoon = round($aging['current'], 2);
        $overdue = round($aging['days_31_60'] + $aging['days_61_90'] + $aging['days_90_plus'], 2);

        // Recent customer payments
        $recentPayments = CustomerPayment::with(['order', 'bankAccount', 'createdByUser'])
            ->orderBy('payment_date', 'desc')
            ->orderBy('id', 'desc')
            ->limit(15)
            ->get();

        return response()->json([
            'success' => true,
            'summary' => [
                'total_invoiced' => $totalInvoiced,
                'total_collected' => $totalCollected,
                'total_due' => $totalDue,
                'collection_rate_percent' => $collectionRate,
                'due_soon' => $dueSoon,
                'overdue' => $overdue,
            ],
            'aging' => [
                'current' => round($aging['current'], 2),
                'days_31_60' => round($aging['days_31_60'], 2),
                'days_61_90' => round($aging['days_61_90'], 2),
                'days_90_plus' => round($aging['days_90_plus'], 2),
                'total' => round($aging['total_receivable'], 2),
            ],
            'receivables' => $activeDues,
            'recent_payments' => $recentPayments,
        ]);
    }

    /**
     * 7. Record Customer Payment Settlement
     */
    public function recordCustomerPayment(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'accounting.manage');

        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|max:50',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'payment_date' => 'required|date',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        $order = Order::findOrFail($validated['order_id']);

        $paymentNumber = 'CPAY-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

        $payment = CustomerPayment::create([
            'payment_number' => $paymentNumber,
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'bank_account_id' => $validated['bank_account_id'] ?? null,
            'payment_date' => $validated['payment_date'],
            'reference_number' => $validated['reference_number'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'created_by_user_id' => $request->user()?->id,
        ]);

        $journalEntry = AccountingService::postCustomerPayment($payment);

        return response()->json([
            'success' => true,
            'message' => "Payment of \${$payment->amount} recorded for Order #{$order->order_number}.",
            'payment' => $payment->load('bankAccount'),
            'journal_entry' => $journalEntry,
        ], 201);
    }

    /**
     * 8. Accounts Payable (Supplier Dues & Aging)
     */
    public function payables(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'accounting.view');

        $goodsReceipts = GoodsReceipt::with(['purchaseOrder', 'vendor', 'items'])
            ->orderBy('received_date', 'desc')
            ->get();

        $now = Carbon::now();
        $aging = [
            'current' => 0.00,
            'days_31_60' => 0.00,
            'days_61_90' => 0.00,
            'days_90_plus' => 0.00,
            'total_payable' => 0.00,
        ];

        // Track unpaid receipts per PO/Vendor
        $openPayables = $goodsReceipts->map(function ($receipt) use ($now, &$aging) {
            $totalReceived = 0.00;
            foreach ($receipt->items as $item) {
                $totalReceived += (float) ($item->quantity_received * $item->unit_cost);
            }

            // Sum payments made for this PO
            $paidAmount = 0.00;
            if ($receipt->purchase_order_id) {
                $paidAmount = (float) SupplierPayment::where('purchase_order_id', $receipt->purchase_order_id)->sum('amount');
            }

            $dueAmount = max(0, round($totalReceived - $paidAmount, 2));
            $receiptDate = Carbon::parse($receipt->received_date ?: $receipt->created_at);
            $daysOpen = (int) $receiptDate->diffInDays($now);

            if ($dueAmount > 0) {
                if ($daysOpen <= 30) {
                    $aging['current'] += $dueAmount;
                } elseif ($daysOpen <= 60) {
                    $aging['days_31_60'] += $dueAmount;
                } elseif ($daysOpen <= 90) {
                    $aging['days_61_90'] += $dueAmount;
                } else {
                    $aging['days_90_plus'] += $dueAmount;
                }
                $aging['total_payable'] += $dueAmount;
            }

            return [
                'id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'po_number' => $receipt->purchaseOrder?->po_number,
                'purchase_order_id' => $receipt->purchase_order_id,
                'vendor_id' => $receipt->vendor_id,
                'vendor_name' => $receipt->vendor?->name,
                'received_date' => $receiptDate->toDateString(),
                'days_open' => $daysOpen,
                'total_amount' => round($totalReceived, 2),
                'paid_amount' => round($paidAmount, 2),
                'due_amount' => $dueAmount,
            ];
        });

        $totalBilled = round($openPayables->sum('total_amount'), 2);
        $totalPaid = round($openPayables->sum('paid_amount'), 2);
        $totalDue = round($aging['total_payable'], 2);
        $disbursementRate = ($totalBilled > 0) ? round(($totalPaid / $totalBilled) * 100, 1) : 0;
        $dueSoon = round($aging['current'], 2);
        $overdue = round($aging['days_31_60'] + $aging['days_61_90'] + $aging['days_90_plus'], 2);

        $recentPayments = SupplierPayment::with(['vendor', 'purchaseOrder', 'bankAccount', 'createdByUser'])
            ->orderBy('payment_date', 'desc')
            ->orderBy('id', 'desc')
            ->limit(15)
            ->get();

        $vendors = Vendor::select('id', 'name', 'vendor_code', 'email', 'phone')->get();

        return response()->json([
            'success' => true,
            'summary' => [
                'total_billed' => $totalBilled,
                'total_paid' => $totalPaid,
                'total_due' => $totalDue,
                'disbursement_rate_percent' => $disbursementRate,
                'due_soon' => $dueSoon,
                'overdue' => $overdue,
            ],
            'aging' => [
                'current' => round($aging['current'], 2),
                'days_31_60' => round($aging['days_31_60'], 2),
                'days_61_90' => round($aging['days_61_90'], 2),
                'days_90_plus' => round($aging['days_90_plus'], 2),
                'total' => round($aging['total_payable'], 2),
            ],
            'payables' => $openPayables->values(),
            'recent_payments' => $recentPayments,
            'vendors' => $vendors,
        ]);
    }

    /**
     * 9. Record Supplier Payment Settle
     */
    public function recordSupplierPayment(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'accounting.manage');

        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|max:50',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'payment_date' => 'required|date',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        $vendor = Vendor::findOrFail($validated['vendor_id']);
        $paymentNumber = 'SPAY-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

        $payment = SupplierPayment::create([
            'payment_number' => $paymentNumber,
            'purchase_order_id' => $validated['purchase_order_id'] ?? null,
            'vendor_id' => $vendor->id,
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'bank_account_id' => $validated['bank_account_id'] ?? null,
            'payment_date' => $validated['payment_date'],
            'reference_number' => $validated['reference_number'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'created_by_user_id' => $request->user()?->id,
        ]);

        $journalEntry = AccountingService::postSupplierPayment($payment);

        return response()->json([
            'success' => true,
            'message' => "Payment of \${$payment->amount} to {$vendor->name} recorded successfully.",
            'payment' => $payment->load('bankAccount', 'vendor'),
            'journal_entry' => $journalEntry,
        ], 201);
    }

    /**
     * 10. Bank & Cash Accounts Register
     */
    public function banking(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'accounting.view');

        // Always sync balances before viewing
        AccountingService::syncBankBalances();

        $bankAccounts = BankAccount::with('chartOfAccount')->get();

        $totalCash = $bankAccounts->where('account_type', 'cash')->sum('current_balance');
        $totalBank = $bankAccounts->where('account_type', 'bank')->sum('current_balance');
        $totalMfs = $bankAccounts->where('account_type', 'mfs')->sum('current_balance');

        // Recent cash & bank ledger movements
        $coaIds = $bankAccounts->pluck('chart_of_account_id')->filter()->values();
        $recentTransactions = JournalEntryLine::with(['journalEntry', 'account'])
            ->whereIn('chart_of_account_id', $coaIds)
            ->whereHas('journalEntry', function ($q) {
                $q->where('status', 'posted');
            })
            ->orderBy('id', 'desc')
            ->limit(25)
            ->get();

        return response()->json([
            'success' => true,
            'accounts' => $bankAccounts,
            'summary' => [
                'total_cash' => round((float) $totalCash, 2),
                'total_bank' => round((float) $totalBank, 2),
                'total_mfs' => round((float) $totalMfs, 2),
                'total_liquidity' => round((float) ($totalCash + $totalBank + $totalMfs), 2),
            ],
            'recent_transactions' => $recentTransactions,
        ]);
    }

    /**
     * 11. Internal Bank/Cash Transfer
     */
    public function transfer(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'accounting.manage');

        $validated = $request->validate([
            'from_bank_account_id' => 'required|exists:bank_accounts,id',
            'to_bank_account_id' => 'required|exists:bank_accounts,id|different:from_bank_account_id',
            'amount' => 'required|numeric|min:0.01',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        $from = BankAccount::findOrFail($validated['from_bank_account_id']);
        $to = BankAccount::findOrFail($validated['to_bank_account_id']);

        try {
            $journalEntry = AccountingService::postFundTransfer(
                $from,
                $to,
                (float) $validated['amount'],
                $validated['reference_number'] ?? '',
                $validated['notes'] ?? '',
                $request->user()
            );

            return response()->json([
                'success' => true,
                'message' => "Transferred \${$validated['amount']} from {$from->account_name} to {$to->account_name}.",
                'journal_entry' => $journalEntry,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * 12. Formal Financial Statements (P&L, Balance Sheet, Cash Flow, Trial Balance)
     */
    public function reports(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'accounting.view', 'finance.reports_view');
        [$startDate, $endDate] = $this->getDateRange($request);

        // Subquery for lines in period
        $periodLines = JournalEntryLine::whereHas('journalEntry', function ($q) use ($startDate, $endDate) {
            $q->where('status', 'posted')
              ->whereBetween('entry_date', [$startDate->toDateString(), $endDate->toDateString()]);
        });

        // Subquery for cumulative lines up to endDate
        $cumulativeLines = JournalEntryLine::whereHas('journalEntry', function ($q) use ($endDate) {
            $q->where('status', 'posted')
              ->where('entry_date', '<=', $endDate->toDateString());
        });

        // ----------------------------------------------------
        // STATEMENT 1: PROFIT & LOSS (INCOME STATEMENT)
        // ----------------------------------------------------
        $revenueDetails = (clone $periodLines)
            ->join('chart_of_accounts', 'journal_entry_lines.chart_of_account_id', '=', 'chart_of_accounts.id')
            ->where('chart_of_accounts.account_type', 'revenue')
            ->select('chart_of_accounts.account_code', 'chart_of_accounts.account_name')
            ->selectRaw('SUM(journal_entry_lines.credit) as gross_credit, SUM(journal_entry_lines.debit) as contra_debit')
            ->groupBy('chart_of_accounts.account_code', 'chart_of_accounts.account_name')
            ->orderBy('chart_of_accounts.account_code')
            ->get()
            ->map(function ($row) {
                $net = (float) $row->gross_credit - (float) $row->contra_debit;
                return [
                    'code' => $row->account_code,
                    'name' => $row->account_name,
                    'amount' => round($net, 2),
                ];
            });

        $totalOperatingRevenue = (float) $revenueDetails->sum('amount');

        $cogsDetails = (clone $periodLines)
            ->join('chart_of_accounts', 'journal_entry_lines.chart_of_account_id', '=', 'chart_of_accounts.id')
            ->where('chart_of_accounts.account_type', 'cogs')
            ->select('chart_of_accounts.account_code', 'chart_of_accounts.account_name')
            ->selectRaw('SUM(journal_entry_lines.debit - journal_entry_lines.credit) as net_cogs')
            ->groupBy('chart_of_accounts.account_code', 'chart_of_accounts.account_name')
            ->orderBy('chart_of_accounts.account_code')
            ->get()
            ->map(fn($row) => [
                'code' => $row->account_code,
                'name' => $row->account_name,
                'amount' => round(max(0, (float) $row->net_cogs), 2),
            ]);

        $totalCogs = (float) $cogsDetails->sum('amount');
        $grossProfit = $totalOperatingRevenue - $totalCogs;
        $grossProfitMargin = $totalOperatingRevenue > 0 ? round(($grossProfit / $totalOperatingRevenue) * 100, 2) : 0;

        $expenseDetails = (clone $periodLines)
            ->join('chart_of_accounts', 'journal_entry_lines.chart_of_account_id', '=', 'chart_of_accounts.id')
            ->where('chart_of_accounts.account_type', 'expense')
            ->select('chart_of_accounts.account_code', 'chart_of_accounts.account_name')
            ->selectRaw('SUM(journal_entry_lines.debit - journal_entry_lines.credit) as net_expense')
            ->groupBy('chart_of_accounts.account_code', 'chart_of_accounts.account_name')
            ->orderBy('chart_of_accounts.account_code')
            ->get()
            ->map(fn($row) => [
                'code' => $row->account_code,
                'name' => $row->account_name,
                'amount' => round(max(0, (float) $row->net_expense), 2),
            ]);

        $totalOperatingExpenses = (float) $expenseDetails->sum('amount');
        $netIncome = $grossProfit - $totalOperatingExpenses;
        $netIncomeMargin = $totalOperatingRevenue > 0 ? round(($netIncome / $totalOperatingRevenue) * 100, 2) : 0;

        $incomeStatement = [
            'revenue_lines' => $revenueDetails,
            'total_revenue' => round($totalOperatingRevenue, 2),
            'cogs_lines' => $cogsDetails,
            'total_cogs' => round($totalCogs, 2),
            'gross_profit' => round($grossProfit, 2),
            'gross_margin_percent' => $grossProfitMargin,
            'expense_lines' => $expenseDetails,
            'total_expenses' => round($totalOperatingExpenses, 2),
            'net_income' => round($netIncome, 2),
            'net_margin_percent' => $netIncomeMargin,
        ];

        // ----------------------------------------------------
        // STATEMENT 2: BALANCE SHEET (POSITION AS OF END DATE)
        // ----------------------------------------------------
        $assetDetails = (clone $cumulativeLines)
            ->join('chart_of_accounts', 'journal_entry_lines.chart_of_account_id', '=', 'chart_of_accounts.id')
            ->where('chart_of_accounts.account_type', 'asset')
            ->select('chart_of_accounts.account_code', 'chart_of_accounts.account_name')
            ->selectRaw('SUM(journal_entry_lines.debit - journal_entry_lines.credit) as net_balance')
            ->groupBy('chart_of_accounts.account_code', 'chart_of_accounts.account_name')
            ->orderBy('chart_of_accounts.account_code')
            ->get()
            ->map(fn($row) => [
                'code' => $row->account_code,
                'name' => $row->account_name,
                'amount' => round((float) $row->net_balance, 2),
            ]);
        $totalAssets = (float) $assetDetails->sum('amount');

        $liabilityDetails = (clone $cumulativeLines)
            ->join('chart_of_accounts', 'journal_entry_lines.chart_of_account_id', '=', 'chart_of_accounts.id')
            ->where('chart_of_accounts.account_type', 'liability')
            ->select('chart_of_accounts.account_code', 'chart_of_accounts.account_name')
            ->selectRaw('SUM(journal_entry_lines.credit - journal_entry_lines.debit) as net_balance')
            ->groupBy('chart_of_accounts.account_code', 'chart_of_accounts.account_name')
            ->orderBy('chart_of_accounts.account_code')
            ->get()
            ->map(fn($row) => [
                'code' => $row->account_code,
                'name' => $row->account_name,
                'amount' => round((float) $row->net_balance, 2),
            ]);
        $totalLiabilities = (float) $liabilityDetails->sum('amount');

        // Equity = Contributed Capital + Cumulative Retained Earnings (all historical revenue - historical cogs - historical expense)
        $equityAccounts = (clone $cumulativeLines)
            ->join('chart_of_accounts', 'journal_entry_lines.chart_of_account_id', '=', 'chart_of_accounts.id')
            ->where('chart_of_accounts.account_type', 'equity')
            ->select('chart_of_accounts.account_code', 'chart_of_accounts.account_name')
            ->selectRaw('SUM(journal_entry_lines.credit - journal_entry_lines.debit) as net_balance')
            ->groupBy('chart_of_accounts.account_code', 'chart_of_accounts.account_name')
            ->get()
            ->map(fn($row) => [
                'code' => $row->account_code,
                'name' => $row->account_name,
                'amount' => round((float) $row->net_balance, 2),
            ]);

        // Cumulative Net Income (Retained Earnings)
        $historicalRev = (float) (clone $cumulativeLines)->whereHas('account', fn($q) => $q->where('account_type', 'revenue'))->selectRaw('SUM(credit - debit) as net')->value('net');
        $historicalCogs = (float) (clone $cumulativeLines)->whereHas('account', fn($q) => $q->where('account_type', 'cogs'))->selectRaw('SUM(debit - credit) as net')->value('net');
        $historicalExp = (float) (clone $cumulativeLines)->whereHas('account', fn($q) => $q->where('account_type', 'expense'))->selectRaw('SUM(debit - credit) as net')->value('net');
        $cumulativeRetainedEarnings = $historicalRev - $historicalCogs - $historicalExp;

        $equityLines = $equityAccounts->toArray();
        $equityLines[] = [
            'code' => '3020',
            'name' => 'Retained Earnings (Cumulative Net Profit)',
            'amount' => round($cumulativeRetainedEarnings, 2),
        ];

        $totalEquity = array_sum(array_column($equityLines, 'amount'));
        $accountingEquationCheck = round($totalAssets, 2) === round($totalLiabilities + $totalEquity, 2);

        $balanceSheet = [
            'as_of_date' => $endDate->toDateString(),
            'assets' => $assetDetails,
            'total_assets' => round($totalAssets, 2),
            'liabilities' => $liabilityDetails,
            'total_liabilities' => round($totalLiabilities, 2),
            'equity' => $equityLines,
            'total_equity' => round($totalEquity, 2),
            'total_liabilities_and_equity' => round($totalLiabilities + $totalEquity, 2),
            'is_balanced' => $accountingEquationCheck,
        ];

        // ----------------------------------------------------
        // STATEMENT 3: CASH FLOW STATEMENT (DIRECT METHOD)
        // ----------------------------------------------------
        $cashAccounts = ChartOfAccount::whereIn('account_code', ['1010', '1020', '1030'])->pluck('id');

        // Cash inflows from customers
        $cashFromCustomers = (float) (clone $periodLines)
            ->whereIn('chart_of_account_id', $cashAccounts)
            ->whereHas('journalEntry', fn($q) => $q->whereIn('reference_type', ['Order', 'CustomerPayment']))
            ->sum('debit');

        // Cash paid for procurement / suppliers
        $cashPaidSuppliers = (float) (clone $periodLines)
            ->whereIn('chart_of_account_id', $cashAccounts)
            ->whereHas('journalEntry', fn($q) => $q->where('reference_type', 'SupplierPayment'))
            ->sum('credit');

        // Cash paid for operating expenses
        $cashPaidExpenses = (float) (clone $periodLines)
            ->whereIn('chart_of_account_id', $cashAccounts)
            ->whereHas('journalEntry', fn($q) => $q->where('reference_type', 'Expense'))
            ->sum('credit');

        $netOperatingCashFlow = $cashFromCustomers - $cashPaidSuppliers - $cashPaidExpenses;

        // Opening cash as of startDate
        $openingCashLine = JournalEntryLine::whereHas('journalEntry', function ($q) use ($startDate) {
            $q->where('status', 'posted')
              ->where('entry_date', '<', $startDate->toDateString());
        })
        ->whereIn('chart_of_account_id', $cashAccounts)
        ->selectRaw('SUM(debit - credit) as bal')
        ->first();
        $openingCash = (float) ($openingCashLine->bal ?? 0);
        $closingCash = $openingCash + $netOperatingCashFlow;

        $cashFlowStatement = [
            'cash_from_customers' => round($cashFromCustomers, 2),
            'cash_paid_suppliers' => round($cashPaidSuppliers, 2),
            'cash_paid_expenses' => round($cashPaidExpenses, 2),
            'net_operating_cash_flow' => round($netOperatingCashFlow, 2),
            'opening_cash_balance' => round($openingCash, 2),
            'closing_cash_balance' => round($closingCash, 2),
        ];

        // ----------------------------------------------------
        // STATEMENT 4: TRIAL BALANCE
        // ----------------------------------------------------
        $trialBalanceAccounts = ChartOfAccount::orderBy('account_code', 'asc')->get()->map(function ($account) use ($cumulativeLines) {
            $lines = (clone $cumulativeLines)->where('chart_of_account_id', $account->id)
                ->selectRaw('SUM(debit) as total_debit, SUM(credit) as total_credit')
                ->first();

            $debit = (float) ($lines->total_debit ?? 0);
            $credit = (float) ($lines->total_credit ?? 0);

            // Calculate net debit / credit ending balance
            $netDebit = 0.00;
            $netCredit = 0.00;

            if ($account->isNormalDebit()) {
                $bal = $debit - $credit;
                if ($bal >= 0) {
                    $netDebit = $bal;
                } else {
                    $netCredit = abs($bal);
                }
            } else {
                $bal = $credit - $debit;
                if ($bal >= 0) {
                    $netCredit = $bal;
                } else {
                    $netDebit = abs($bal);
                }
            }

            return [
                'code' => $account->account_code,
                'name' => $account->account_name,
                'type' => $account->account_type,
                'total_debit' => round($debit, 2),
                'total_credit' => round($credit, 2),
                'ending_debit' => round($netDebit, 2),
                'ending_credit' => round($netCredit, 2),
            ];
        })->filter(fn($a) => $a['total_debit'] > 0 || $a['total_credit'] > 0)->values();

        $totalTbDebit = (float) $trialBalanceAccounts->sum('ending_debit');
        $totalTbCredit = (float) $trialBalanceAccounts->sum('ending_credit');

        $trialBalance = [
            'accounts' => $trialBalanceAccounts,
            'total_debit' => round($totalTbDebit, 2),
            'total_credit' => round($totalTbCredit, 2),
            'is_balanced' => round($totalTbDebit, 2) === round($totalTbCredit, 2),
        ];

        return response()->json([
            'success' => true,
            'period' => [
                'from' => $startDate->toDateString(),
                'to' => $endDate->toDateString(),
            ],
            'income_statement' => $incomeStatement,
            'balance_sheet' => $balanceSheet,
            'cash_flow' => $cashFlowStatement,
            'trial_balance' => $trialBalance,
        ]);
    }

    /**
     * 13. Export Financial Statement or Ledger to CSV
     */
    public function export(Request $request): StreamedResponse
    {
        $this->checkPermission($request, 'accounting.view', 'finance.reports_view');

        $reportType = $request->input('type', 'trial_balance');
        $fileName = "accounting-{$reportType}-" . date('Y-m-d') . ".csv";

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->stream(function () use ($reportType, $request) {
            $handle = fopen('php://output', 'w');
            // Write UTF-8 BOM for Excel compatibility
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            if ($reportType === 'ledger') {
                fputcsv($handle, ['Entry #', 'Date', 'Type', 'Reference #', 'Narration', 'Account Code', 'Account Name', 'Debit ($)', 'Credit ($)', 'Memo']);
                JournalEntryLine::with(['journalEntry', 'account'])
                    ->chunk(100, function ($lines) use ($handle) {
                        foreach ($lines as $line) {
                            fputcsv($handle, [
                                $line->journalEntry?->entry_number,
                                $line->journalEntry?->entry_date?->toDateString(),
                                $line->journalEntry?->reference_type,
                                $line->journalEntry?->reference_number,
                                $line->journalEntry?->narration,
                                $line->account?->account_code,
                                $line->account?->account_name,
                                number_format($line->debit, 2, '.', ''),
                                number_format($line->credit, 2, '.', ''),
                                $line->memo,
                            ]);
                        }
                    });
            } else {
                // Default: Trial Balance
                fputcsv($handle, ['Account Code', 'Account Name', 'Classification', 'Debit ($)', 'Credit ($)']);
                $accounts = ChartOfAccount::orderBy('account_code', 'asc')->get();
                $totDeb = 0;
                $totCred = 0;
                foreach ($accounts as $acc) {
                    $bal = $acc->balance;
                    $deb = $acc->isNormalDebit() ? max(0, $bal) : 0;
                    $cred = !$acc->isNormalDebit() ? max(0, $bal) : 0;
                    if ($deb > 0 || $cred > 0) {
                        $totDeb += $deb;
                        $totCred += $cred;
                        fputcsv($handle, [
                            $acc->account_code,
                            $acc->account_name,
                            ucfirst($acc->account_type),
                            number_format($deb, 2, '.', ''),
                            number_format($cred, 2, '.', ''),
                        ]);
                    }
                }
                fputcsv($handle, ['TOTAL', '', '', number_format($totDeb, 2, '.', ''), number_format($totCred, 2, '.', '')]);
            }

            fclose($handle);
        }, 200, $headers);
    }
}
