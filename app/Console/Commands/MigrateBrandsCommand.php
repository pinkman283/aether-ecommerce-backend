<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MigrateBrandsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'catalog:migrate-brands {--dry-run : Simulate migration without writing to database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely migrates legacy product string brands to brands table and populates products.brand_id';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info($dryRun ? '=== [DRY RUN] Catalog Brand Migration ===' : '=== Starting Catalog Brand Migration ===');

        // Fetch products that have a non-empty brand string but no brand_id
        $products = Product::whereNotNull('brand')
            ->where('brand', '!=', '')
            ->whereNull('brand_id')
            ->get(['id', 'name', 'brand', 'category_id']);

        if ($products->isEmpty()) {
            $this->info('No products with unmigrated string brands found. Everything is up to date.');
            return Command::SUCCESS;
        }

        $this->info("Found {$products->count()} product(s) needing brand migration.");

        // Group by normalized brand name
        $grouped = $products->groupBy(function ($item) {
            return trim($item->brand);
        });

        $rows = [];
        $migratedCount = 0;

        foreach ($grouped as $brandName => $prods) {
            $productCount = $prods->count();
            $slug = Str::slug($brandName);

            // Check if brand already exists in brands table (case-insensitive)
            $existingBrand = Brand::whereRaw('LOWER(name) = ?', [strtolower($brandName)])->first();

            if ($existingBrand) {
                $status = "Existing Brand (ID: {$existingBrand->id})";
                $targetBrandId = $existingBrand->id;
            } else {
                $status = "NEW Brand to Create (Slug: {$slug})";
                $targetBrandId = null;
            }

            $rows[] = [
                'Brand Name' => $brandName,
                'Products Count' => $productCount,
                'Action' => $status,
                'Target Slug' => $existingBrand ? $existingBrand->slug : $slug,
            ];

            if (!$dryRun) {
                DB::transaction(function () use ($brandName, $slug, $existingBrand, $prods, &$migratedCount) {
                    $brand = $existingBrand ?: Brand::create([
                        'name' => $brandName,
                        'slug' => $slug,
                        'is_featured' => false,
                        'display_order' => 0,
                    ]);

                    foreach ($prods as $prod) {
                        DB::table('products')
                            ->where('id', $prod->id)
                            ->update([
                                'brand_id' => $brand->id,
                                'brand' => $brand->name, // keep in sync
                                'updated_at' => now(),
                            ]);

                        // Sync category_brand pivot if category_id exists
                        if ($prod->category_id) {
                            DB::table('category_brand')->updateOrInsert(
                                ['category_id' => $prod->category_id, 'brand_id' => $brand->id],
                                ['updated_at' => now(), 'created_at' => now()]
                            );
                        }

                        $migratedCount++;
                    }
                });
            }
        }

        $this->table(['Brand Name', 'Products Count', 'Action', 'Target Slug'], $rows);

        if ($dryRun) {
            $this->warn('[DRY RUN COMPLETE] No database changes were made. Run without --dry-run to apply changes.');
        } else {
            $this->info("Successfully migrated {$migratedCount} product(s) to dedicated brand relations!");
        }

        return Command::SUCCESS;
    }
}
