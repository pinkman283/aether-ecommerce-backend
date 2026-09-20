<?php

use App\Models\ChartOfAccount;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Ensure default chart of accounts are populated if missing
        if (ChartOfAccount::where('account_code', '1100')->doesntExist() || ChartOfAccount::count() < 10) {
            (new ChartOfAccountsSeeder())->run();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Don't delete accounting data on rollback to avoid foreign key violations
    }
};
