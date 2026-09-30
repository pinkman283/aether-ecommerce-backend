<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncVariantStockCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'products:sync-variant-stock 
                            {--dry-run : Scan and report stock mismatches without making changes} 
                            {--fix : Repair parent product stock to match variant stock}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile and synchronize parent product stock_quantity with the sum of child variant stock_quantity';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isFix = (bool) $this->option('fix');
        $isDryRun = (bool) $this->option('dry-run');

        if (!$isFix && !$isDryRun) {
            $this->info("Notice: No mode specified. Running in safe --dry-run mode by default.");
            $isDryRun = true;
        }

        $modeLabel = $isFix ? 'FIX' : 'DRY RUN';
        $this->info("=== Scanning for Parent/Variant Stock Mismatches ({$modeLabel}) ===");

        // Query all products that have variants
        $productsWithVariants = Product::has('variants')->with('variants')->get();
        $totalChecked = $productsWithVariants->count();
        $this->line("Found {$totalChecked} product(s) configured with variants.");

        $mismatches = [];

        foreach ($productsWithVariants as $product) {
            $currentStock = (int) $product->stock_quantity;
            $expectedStock = (int) $product->variants->sum('stock_quantity');

            if ($currentStock !== $expectedStock) {
                $mismatches[] = [
                    'product' => $product,
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'current_stock' => $currentStock,
                    'expected_stock' => $expectedStock,
                    'diff' => $expectedStock - $currentStock,
                ];
            }
        }

        if (empty($mismatches)) {
            $this->info("All {$totalChecked} product(s) with variants have perfectly synchronized stock. No mismatches found.");
            return 0;
        }

        $this->warn("Identified " . count($mismatches) . " product(s) with stock discrepancies:");

        $tableRows = array_map(function ($item) {
            return [
                $item['id'],
                $item['sku'],
                mb_strimwidth($item['name'], 0, 32, '...'),
                $item['current_stock'],
                $item['expected_stock'],
                ($item['diff'] > 0 ? "+{$item['diff']}" : (string)$item['diff']),
            ];
        }, $mismatches);

        $this->table(
            ['Product ID', 'SKU', 'Product Name', 'Parent Stock', 'Variant Sum', 'Difference'],
            $tableRows
        );

        if ($isFix) {
            $this->line("\nApplying stock repairs...");
            DB::transaction(function () use ($mismatches) {
                foreach ($mismatches as $item) {
                    /** @var Product $product */
                    $product = $item['product'];
                    $expectedStock = $item['expected_stock'];
                    
                    // Quiet update to ensure parent stock equals variant sum without triggering side effects
                    $product->updateQuietly([
                        'stock_quantity' => $expectedStock,
                    ]);
                }
            });

            $this->info("Successfully synchronized parent stock for " . count($mismatches) . " product(s).");
            $this->line("Variant stock quantities, inventory cost layers, and ledger records remain untouched.");
        } else {
            $this->info("\nDry run complete. No database changes were made.");
            $this->line("To repair these discrepancies, re-run with: php artisan products:sync-variant-stock --fix");
        }

        return 0;
    }
}
