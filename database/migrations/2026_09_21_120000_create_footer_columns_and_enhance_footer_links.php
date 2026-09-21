<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create footer_columns table if it doesn't exist
        if (!Schema::hasTable('footer_columns')) {
            Schema::create('footer_columns', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->integer('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // 2. Enhance footer_links table
        Schema::table('footer_links', function (Blueprint $table) {
            if (!Schema::hasColumn('footer_links', 'footer_column_id')) {
                $table->foreignId('footer_column_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('footer_columns')
                    ->cascadeOnDelete();
            }
            if (!Schema::hasColumn('footer_links', 'is_external')) {
                $table->boolean('is_external')->default(false)->after('url');
            }
            if (!Schema::hasColumn('footer_links', 'open_in_new_tab')) {
                $table->boolean('open_in_new_tab')->default(false)->after('is_external');
            }
        });

        // 3. Clean legacy AETHER data and seed initial Inheliq vape footer columns & links
        DB::table('footer_links')->truncate();
        DB::table('footer_columns')->truncate();

        $initialColumns = [
            [
                'title' => 'SHOP',
                'sort_order' => 1,
                'is_active' => true,
                'links' => [
                    ['title' => 'All Products', 'url' => '/products', 'sort_order' => 1, 'is_external' => false, 'open_in_new_tab' => false],
                    ['title' => 'Disposable Vapes', 'url' => '/products?category=disposable-vapes', 'sort_order' => 2, 'is_external' => false, 'open_in_new_tab' => false],
                    ['title' => 'Pod Systems', 'url' => '/products?category=pod-systems', 'sort_order' => 3, 'is_external' => false, 'open_in_new_tab' => false],
                    ['title' => 'E-Liquids', 'url' => '/products?category=e-liquids', 'sort_order' => 4, 'is_external' => false, 'open_in_new_tab' => false],
                    ['title' => 'Vape Accessories', 'url' => '/products?category=vape-accessories', 'sort_order' => 5, 'is_external' => false, 'open_in_new_tab' => false],
                    ['title' => 'New Arrivals', 'url' => '/products?sort=newest', 'sort_order' => 6, 'is_external' => false, 'open_in_new_tab' => false],
                    ['title' => 'Best Sellers', 'url' => '/products?sort=best-selling', 'sort_order' => 7, 'is_external' => false, 'open_in_new_tab' => false],
                ],
            ],
            [
                'title' => 'CUSTOMER CARE',
                'sort_order' => 2,
                'is_active' => true,
                'links' => [
                    ['title' => 'Contact Us', 'url' => '/contact', 'sort_order' => 1, 'is_external' => false, 'open_in_new_tab' => false],
                    ['title' => 'Track Order', 'url' => '/track', 'sort_order' => 2, 'is_external' => false, 'open_in_new_tab' => false],
                    ['title' => 'Shipping & Delivery', 'url' => '/shipping-policy', 'sort_order' => 3, 'is_external' => false, 'open_in_new_tab' => false],
                    ['title' => 'Returns & Refunds', 'url' => '/refund-policy', 'sort_order' => 4, 'is_external' => false, 'open_in_new_tab' => false],
                    ['title' => 'FAQ', 'url' => '/faq', 'sort_order' => 5, 'is_external' => false, 'open_in_new_tab' => false],
                    ['title' => 'Support', 'url' => '/contact', 'sort_order' => 6, 'is_external' => false, 'open_in_new_tab' => false],
                ],
            ],
            [
                'title' => 'INFORMATION',
                'sort_order' => 3,
                'is_active' => true,
                'links' => [
                    ['title' => 'About Us', 'url' => '/about', 'sort_order' => 1, 'is_external' => false, 'open_in_new_tab' => false],
                    ['title' => 'Age Verification', 'url' => '/pages/age-verification', 'sort_order' => 2, 'is_external' => false, 'open_in_new_tab' => false],
                    ['title' => 'Privacy Policy', 'url' => '/privacy', 'sort_order' => 3, 'is_external' => false, 'open_in_new_tab' => false],
                    ['title' => 'Terms & Conditions', 'url' => '/terms', 'sort_order' => 4, 'is_external' => false, 'open_in_new_tab' => false],
                    ['title' => 'Cookie Policy', 'url' => '/pages/cookie-policy', 'sort_order' => 5, 'is_external' => false, 'open_in_new_tab' => false],
                ],
            ],
            [
                'title' => 'QUICK LINKS',
                'sort_order' => 4,
                'is_active' => true,
                'links' => [
                    ['title' => 'My Account', 'url' => '/dashboard', 'sort_order' => 1, 'is_external' => false, 'open_in_new_tab' => false],
                    ['title' => 'Cart', 'url' => '/checkout', 'sort_order' => 2, 'is_external' => false, 'open_in_new_tab' => false],
                    ['title' => 'Wishlist', 'url' => '/dashboard', 'sort_order' => 3, 'is_external' => false, 'open_in_new_tab' => false],
                    ['title' => 'Promotions', 'url' => '/promotions', 'sort_order' => 4, 'is_external' => false, 'open_in_new_tab' => false],
                    ['title' => 'Order History', 'url' => '/dashboard', 'sort_order' => 5, 'is_external' => false, 'open_in_new_tab' => false],
                ],
            ],
        ];

        $now = now();
        foreach ($initialColumns as $colData) {
            $columnId = DB::table('footer_columns')->insertGetId([
                'title' => $colData['title'],
                'sort_order' => $colData['sort_order'],
                'is_active' => $colData['is_active'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($colData['links'] as $linkData) {
                DB::table('footer_links')->insert([
                    'footer_column_id' => $columnId,
                    'column_group' => $colData['title'],
                    'title' => $linkData['title'],
                    'url' => $linkData['url'],
                    'is_external' => $linkData['is_external'],
                    'open_in_new_tab' => $linkData['open_in_new_tab'],
                    'sort_order' => $linkData['sort_order'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('footer_links', function (Blueprint $table) {
            if (Schema::hasColumn('footer_links', 'footer_column_id')) {
                $table->dropForeign(['footer_column_id']);
                $table->dropColumn('footer_column_id');
            }
            if (Schema::hasColumn('footer_links', 'is_external')) {
                $table->dropColumn('is_external');
            }
            if (Schema::hasColumn('footer_links', 'open_in_new_tab')) {
                $table->dropColumn('open_in_new_tab');
            }
        });

        Schema::dropIfExists('footer_columns');
    }
};
