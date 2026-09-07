<?php

namespace App\Console\Commands;

use App\Models\ChartOfAccount;
use App\Models\Expense;
use App\Models\GoodsReceipt;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Order;
use App\Services\AccountingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Console\Command;

class SyncHistoricalAccounting extends Command
{
    protected $signature = 'accounting:sync-historical {--fresh : Wipe existing journal entries before syncing}';

    protected $description = 'Sync historical orders, purchase goods receipts, and operational expenses into double-entry journal entries';

    public function handle(): int
    {
        $this->info('Starting historical financial data synchronization...');

        // 1. Ensure COA exists
        if (ChartOfAccount::count() === 0) {
            $this->warn('Chart of Accounts is empty. Seeding standard COA now...');
            $seeder = new ChartOfAccountsSeeder();
            $seeder->run();
        }

        if ($this->option('fresh')) {
            $this->warn('Wiping existing journal entries, payments, and lines as requested (--fresh)...');
            JournalEntryLine::query()->delete();
            JournalEntry::query()->delete();
        }

        // 2. Sync Orders
        $orders = Order::where('order_status', '!=', 'cancelled')->orderBy('id', 'asc')->get();
        $this->info("Found {$orders->count()} non-cancelled orders to sync...");

        $syncedOrders = 0;
        foreach ($orders as $order) {
            $entry = AccountingService::postOrderSale($order);
            if ($entry) {
                $syncedOrders++;
            }
        }
        $this->line("  ✓ Synced {$syncedOrders} order transactions into double-entry ledger.");

        // 3. Sync Goods Receipts (Procurement Inventory & Payables)
        $receipts = GoodsReceipt::with('items', 'vendor', 'purchaseOrder')->orderBy('id', 'asc')->get();
        $this->info("Found {$receipts->count()} goods receipts to sync...");

        $syncedReceipts = 0;
        foreach ($receipts as $receipt) {
            $entry = AccountingService::postGoodsReceipt($receipt);
            if ($entry) {
                $syncedReceipts++;
            }
        }
        $this->line("  ✓ Synced {$syncedReceipts} inventory procurement receipts.");

        // 4. Sync Expenses
        $expenses = Expense::with('category')->orderBy('id', 'asc')->get();
        $this->info("Found {$expenses->count()} expenses to sync...");

        $syncedExpenses = 0;
        foreach ($expenses as $expense) {
            $entry = AccountingService::postExpense($expense);
            if ($entry) {
                $syncedExpenses++;
            }
        }
        $this->line("  ✓ Synced {$syncedExpenses} operational expenses.");

        // 5. Sync Bank Balances
        AccountingService::syncBankBalances();
        $this->line("  ✓ Synchronized all cash, bank, and MFS balances.");

        // 6. Audit Trial Balance Total
        $totalDebit = (float) JournalEntryLine::sum('debit');
        $totalCredit = (float) JournalEntryLine::sum('credit');
        $difference = round(abs($totalDebit - $totalCredit), 2);

        $this->newLine();
        $this->info('========================================');
        $this->info('HISTORICAL ACCOUNTING AUDIT SUMMARY:');
        $this->line(sprintf('Total Journal Entries : %d', JournalEntry::count()));
        $this->line(sprintf('Total Ledger Lines   : %d', JournalEntryLine::count()));
        $this->line(sprintf('Total System Debits  : $%s', number_format($totalDebit, 2)));
        $this->line(sprintf('Total System Credits : $%s', number_format($totalCredit, 2)));
        $this->line(sprintf('Trial Balance Variance: $%s', number_format($difference, 2)));

        if ($difference === 0.00) {
            $this->info('STATUS: PERFECT ACCRUAL BALANCE [Debit == Credit]. Double-entry integrity verified.');
        } else {
            $this->error("STATUS: MISMATCH DETECTED! Variance of \${$difference}");
            return Command::FAILURE;
        }
        $this->info('========================================');

        return Command::SUCCESS;
    }
}
