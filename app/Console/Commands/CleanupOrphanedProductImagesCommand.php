<?php

namespace App\Console\Commands;

use App\Models\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupOrphanedProductImagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'products:cleanup-orphaned-images 
                            {--dry-run : Scan and report orphaned images without deleting}
                            {--delete : Explicitly delete identified orphaned files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scans storage/app/public/products for files not referenced by product_images table and cleans them up.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $disk = Storage::disk('public');
        $directory = 'products';

        if (!$disk->exists($directory)) {
            $this->info("Directory '{$directory}' does not exist on public disk. No files to scan.");
            return Command::SUCCESS;
        }

        $isDelete = (bool) $this->option('delete');
        $isDryRun = (bool) $this->option('dry-run');

        // Default to dry-run if --delete was not explicitly requested
        if (!$isDelete && !$isDryRun) {
            $isDryRun = true;
            $this->comment("Notice: No mode specified. Running in safe --dry-run mode by default.");
        }

        $this->info($isDelete ? "=== Scanning for Orphaned Product Images (DELETE MODE) ===" : "=== Scanning for Orphaned Product Images (DRY RUN) ===");

        // 1. Gather all physical files in products/
        $allFiles = $disk->files($directory);
        $totalFilesCount = count($allFiles);

        $this->line("Found {$totalFilesCount} physical file(s) in '{$directory}'.");

        if ($totalFilesCount === 0) {
            $this->info("No files found in '{$directory}'. Nothing to clean.");
            return Command::SUCCESS;
        }

        // 2. Build set of referenced storage paths and filenames from product_images table
        $referencedPaths = [];
        $referencedFilenames = [];

        ProductImage::query()->select(['image_url'])->chunk(200, function ($images) use (&$referencedPaths, &$referencedFilenames) {
            foreach ($images as $img) {
                $path = $img->getRelativeStoragePath();
                if ($path) {
                    $referencedPaths[$path] = true;
                    $referencedFilenames[basename($path)] = true;
                }
            }
        });

        // 3. Identify orphaned files
        $orphanedFiles = [];
        $totalOrphanBytes = 0;

        foreach ($allFiles as $file) {
            $filename = basename($file);

            // Skip hidden or system files
            if (str_starts_with($filename, '.')) {
                continue;
            }

            // Check if referenced
            $isReferenced = isset($referencedPaths[$file]) || isset($referencedFilenames[$filename]);

            if (!$isReferenced) {
                $size = $disk->size($file);
                $totalOrphanBytes += $size;
                $orphanedFiles[] = [
                    'path' => $file,
                    'filename' => $filename,
                    'size' => $size,
                    'size_formatted' => round($size / 1024, 2) . ' KB',
                    'last_modified' => date('Y-m-d H:i:s', $disk->lastModified($file)),
                ];
            }
        }

        $orphanCount = count($orphanedFiles);

        if ($orphanCount === 0) {
            $this->info("All {$totalFilesCount} file(s) in '{$directory}' are actively referenced by product_images. No orphans found.");
            return Command::SUCCESS;
        }

        $this->warn("Identified {$orphanCount} orphaned file(s) occupying " . round($totalOrphanBytes / 1024, 2) . " KB.");

        // Display report table
        $tableRows = array_map(function ($f) {
            return [$f['filename'], $f['size_formatted'], $f['last_modified']];
        }, array_slice($orphanedFiles, 0, 50));

        $this->table(['Filename', 'Size', 'Last Modified'], $tableRows);

        if ($orphanCount > 50) {
            $this->line("... and " . ($orphanCount - 50) . " more file(s).");
        }

        // 4. Handle deletion if --delete requested
        if ($isDelete) {
            $deletedCount = 0;
            $failedCount = 0;

            foreach ($orphanedFiles as $orphan) {
                try {
                    if ($disk->delete($orphan['path'])) {
                        $deletedCount++;
                    } else {
                        $failedCount++;
                    }
                } catch (\Throwable $e) {
                    $failedCount++;
                    $this->error("Failed to delete {$orphan['path']}: " . $e->getMessage());
                }
            }

            $this->info("Cleanup complete. Successfully deleted {$deletedCount} orphaned file(s) (" . round($totalOrphanBytes / 1024, 2) . " KB reclaimed).");
            if ($failedCount > 0) {
                $this->error("{$failedCount} file(s) could not be deleted.");
            }
        } else {
            $this->comment("Dry-run complete. No files were deleted.");
            $this->comment("Run `php artisan products:cleanup-orphaned-images --delete` to delete these files.");
        }

        return Command::SUCCESS;
    }
}
