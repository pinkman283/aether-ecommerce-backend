<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\Setting;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('brand_logos')) {
            Schema::create('brand_logos', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->text('image_url');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('brand_logo_placements')) {
            Schema::create('brand_logo_placements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('logo_id')->constrained('brand_logos')->onDelete('cascade');
                $table->string('placement', 50)->unique();
                $table->timestamps();
            });
        }

        // Migrate existing logo data safely
        try {
            $existingLogo = Setting::where('key', 'store_brand_logo')->value('value');
            if (!empty($existingLogo)) {
                $logoId = DB::table('brand_logos')->insertGetId([
                    'name' => 'Primary Brand Mark',
                    'image_url' => $existingLogo,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach (['navbar', 'mobile_navbar', 'footer'] as $placement) {
                    DB::table('brand_logo_placements')->insertOrIgnore([
                        'logo_id' => $logoId,
                        'placement' => $placement,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $splitLogo = Setting::where('key', 'split_reveal_logo')->value('value');
            if (!empty($splitLogo) && $splitLogo !== $existingLogo) {
                $splitLogoId = DB::table('brand_logos')->insertGetId([
                    'name' => 'Split Reveal Logo',
                    'image_url' => $splitLogo,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('brand_logo_placements')->insertOrIgnore([
                    'logo_id' => $splitLogoId,
                    'placement' => 'split_reveal',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Ensure store_favicon setting exists
            if (!Setting::where('key', 'store_favicon')->exists()) {
                Setting::create([
                    'key' => 'store_favicon',
                    'value' => null,
                    'group' => 'branding',
                    'type' => 'string',
                    'label' => 'Store Favicon',
                    'description' => 'Browser tab and shortcut icon (512x512 recommended)',
                ]);
            }
        } catch (\Throwable $e) {
            // Log notice if seed/migration is running during initialization
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brand_logo_placements');
        Schema::dropIfExists('brand_logos');
    }
};
