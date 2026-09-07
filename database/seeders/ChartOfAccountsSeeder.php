<?php

namespace Database\Seeders;

use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            // 1000 Assets
            [
                'account_code' => '1010',
                'account_name' => 'Cash on Hand',
                'account_type' => 'asset',
                'is_system' => true,
                'is_active' => true,
                'description' => 'Physical cash in cash registers, POS drawers, and office petty cash.',
            ],
            [
                'account_code' => '1020',
                'account_name' => 'Main Business Bank Account',
                'account_type' => 'asset',
                'is_system' => true,
                'is_active' => true,
                'description' => 'Primary commercial bank account for company operations and wire transfers.',
            ],
            [
                'account_code' => '1030',
                'account_name' => 'Digital Wallets & MFS',
                'account_type' => 'asset',
                'is_system' => true,
                'is_active' => true,
                'description' => 'Mobile Financial Services accounts (bKash, Nagad, Stripe, PayPal balances).',
            ],
            [
                'account_code' => '1100',
                'account_name' => 'Accounts Receivable (A/R)',
                'account_type' => 'asset',
                'is_system' => true,
                'is_active' => true,
                'description' => 'Outstanding dues owed by customers for orders fulfilled on credit or pending payment.',
            ],
            [
                'account_code' => '1200',
                'account_name' => 'Merchandise Inventory',
                'account_type' => 'asset',
                'is_system' => true,
                'is_active' => true,
                'description' => 'Current valuation of unsold inventory stock held in warehouse (FIFO asset).',
            ],
            [
                'account_code' => '1500',
                'account_name' => 'Equipment & Fixed Assets',
                'account_type' => 'asset',
                'is_system' => false,
                'is_active' => true,
                'description' => 'Physical assets, computer systems, and store fixtures owned by the business.',
            ],

            // 2000 Liabilities
            [
                'account_code' => '2010',
                'account_name' => 'Accounts Payable (A/P)',
                'account_type' => 'liability',
                'is_system' => true,
                'is_active' => true,
                'description' => 'Unpaid invoices and balances owed to vendors and suppliers for purchase orders.',
            ],
            [
                'account_code' => '2020',
                'account_name' => 'Customer Advances & Store Credit',
                'account_type' => 'liability',
                'is_system' => true,
                'is_active' => true,
                'description' => 'Customer pre-payments, gift cards, and outstanding wallet credits.',
            ],
            [
                'account_code' => '2030',
                'account_name' => 'Sales Tax / VAT Payable',
                'account_type' => 'liability',
                'is_system' => true,
                'is_active' => true,
                'description' => 'Taxes collected on customer sales awaiting remittance to tax authorities.',
            ],
            [
                'account_code' => '2040',
                'account_name' => 'Accrued Operational Liabilities',
                'account_type' => 'liability',
                'is_system' => false,
                'is_active' => true,
                'description' => 'Accrued expenses, utilities, and wages incurred but not yet settled.',
            ],

            // 3000 Equity
            [
                'account_code' => '3010',
                'account_name' => "Owner's Capital",
                'account_type' => 'equity',
                'is_system' => true,
                'is_active' => true,
                'description' => 'Initial and ongoing capital invested into the business by owners.',
            ],
            [
                'account_code' => '3020',
                'account_name' => 'Retained Earnings',
                'account_type' => 'equity',
                'is_system' => true,
                'is_active' => true,
                'description' => 'Cumulative net profit or loss retained in the business over fiscal periods.',
            ],

            // 4000 Revenue
            [
                'account_code' => '4010',
                'account_name' => 'Online Sales Revenue',
                'account_type' => 'revenue',
                'is_system' => true,
                'is_active' => true,
                'description' => 'Gross income earned from web storefront product sales.',
            ],
            [
                'account_code' => '4020',
                'account_name' => 'POS & In-Store Sales Revenue',
                'account_type' => 'revenue',
                'is_system' => true,
                'is_active' => true,
                'description' => 'Gross income earned through retail POS counter registers.',
            ],
            [
                'account_code' => '4030',
                'account_name' => 'Shipping & Delivery Income',
                'account_type' => 'revenue',
                'is_system' => true,
                'is_active' => true,
                'description' => 'Shipping fees billed to customers on fulfilled orders.',
            ],
            [
                'account_code' => '4090',
                'account_name' => 'Sales Discounts & Promotions',
                'account_type' => 'revenue',
                'is_system' => true,
                'is_active' => true,
                'description' => 'Contra-revenue account tracking coupon vouchers and promotional discounts granted.',
            ],
            [
                'account_code' => '4095',
                'account_name' => 'Sales Returns & Refunds',
                'account_type' => 'revenue',
                'is_system' => true,
                'is_active' => true,
                'description' => 'Contra-revenue account for customer order returns, chargebacks, and full refunds.',
            ],

            // 5000 Cost of Goods Sold
            [
                'account_code' => '5010',
                'account_name' => 'Cost of Goods Sold - Online',
                'account_type' => 'cogs',
                'is_system' => true,
                'is_active' => true,
                'description' => 'Direct inventory acquisition cost for goods sold via the online store.',
            ],
            [
                'account_code' => '5020',
                'account_name' => 'Cost of Goods Sold - POS',
                'account_type' => 'cogs',
                'is_system' => true,
                'is_active' => true,
                'description' => 'Direct inventory acquisition cost for goods sold through POS registers.',
            ],
            [
                'account_code' => '5030',
                'account_name' => 'Inventory Shrinkage & Loss',
                'account_type' => 'cogs',
                'is_system' => false,
                'is_active' => true,
                'description' => 'COGS write-down due to damaged items, expiration, or inventory variance.',
            ],

            // 6000 Operating Expenses
            [
                'account_code' => '6010',
                'account_name' => 'Rent & Facility Expense',
                'account_type' => 'expense',
                'is_system' => false,
                'is_active' => true,
                'description' => 'Lease payments for office premises, retail store, and storage warehouses.',
            ],
            [
                'account_code' => '6020',
                'account_name' => 'Salaries & Staff Compensation',
                'account_type' => 'expense',
                'is_system' => false,
                'is_active' => true,
                'description' => 'Staff payroll, bonuses, and contractual compensation.',
            ],
            [
                'account_code' => '6030',
                'account_name' => 'Marketing & Advertising',
                'account_type' => 'expense',
                'is_system' => false,
                'is_active' => true,
                'description' => 'Digital ad spend, influencer marketing, sponsorships, and campaigns.',
            ],
            [
                'account_code' => '6040',
                'account_name' => 'Courier & Logistics Expense',
                'account_type' => 'expense',
                'is_system' => false,
                'is_active' => true,
                'description' => 'Third-party delivery partners, fulfillment costs, and packaging supplies.',
            ],
            [
                'account_code' => '6050',
                'account_name' => 'Utilities, Electric & Internet',
                'account_type' => 'expense',
                'is_system' => false,
                'is_active' => true,
                'description' => 'Electricity, high-speed internet, water, and building maintenance.',
            ],
            [
                'account_code' => '6060',
                'account_name' => 'Software & Technology Services',
                'account_type' => 'expense',
                'is_system' => false,
                'is_active' => true,
                'description' => 'Cloud hosting, SaaS subscriptions, email APIs, and security tools.',
            ],
            [
                'account_code' => '6070',
                'account_name' => 'Payment Gateway & Banking Fees',
                'account_type' => 'expense',
                'is_system' => false,
                'is_active' => true,
                'description' => 'Transaction fee deductions from payment processors and bank charges.',
            ],
            [
                'account_code' => '6090',
                'account_name' => 'General & Miscellaneous Expenses',
                'account_type' => 'expense',
                'is_system' => false,
                'is_active' => true,
                'description' => 'Other petty and unclassified operational expenses.',
            ],
        ];

        foreach ($accounts as $acc) {
            ChartOfAccount::updateOrCreate(
                ['account_code' => $acc['account_code']],
                $acc
            );
        }

        // Seed Default Bank & Cash Accounts
        $cashCoa = ChartOfAccount::where('account_code', '1010')->first();
        $bankCoa = ChartOfAccount::where('account_code', '1020')->first();
        $mfsCoa = ChartOfAccount::where('account_code', '1030')->first();

        BankAccount::updateOrCreate(
            ['account_name' => 'Main Cash Drawer / Petty Cash'],
            [
                'account_type' => 'cash',
                'chart_of_account_id' => $cashCoa?->id,
                'opening_balance' => 0.00,
                'is_active' => true,
            ]
        );

        BankAccount::updateOrCreate(
            ['account_name' => 'City Bank Corporate Account'],
            [
                'account_type' => 'bank',
                'account_number' => '110-234-8899',
                'bank_name' => 'City Bank PLC',
                'branch_name' => 'Gulshan Avenue Commercial Branch',
                'chart_of_account_id' => $bankCoa?->id,
                'opening_balance' => 0.00,
                'is_active' => true,
            ]
        );

        BankAccount::updateOrCreate(
            ['account_name' => 'bKash Merchant Wallet'],
            [
                'account_type' => 'mfs',
                'account_number' => '01711-890234',
                'bank_name' => 'bKash Limited',
                'chart_of_account_id' => $mfsCoa?->id,
                'opening_balance' => 0.00,
                'is_active' => true,
            ]
        );
    }
}
