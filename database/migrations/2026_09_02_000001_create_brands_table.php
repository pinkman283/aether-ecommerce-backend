<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('logo')->nullable();
            $table->string('website')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });

        // Populate initial brands from existing products if any
        try {
            $existingBrands = DB::table('products')
                ->whereNotNull('brand')
                ->where('brand', '!=', '')
                ->distinct()
                ->pluck('brand');

            $order = 1;
            foreach ($existingBrands as $brandName) {
                $slug = Str::slug($brandName);
                if (DB::table('brands')->where('slug', $slug)->exists()) {
                    $slug .= '-' . Str::random(4);
                }

                DB::table('brands')->insert([
                    'name' => $brandName,
                    'slug' => $slug,
                    'description' => "Official manufacturer of {$brandName} hardware and audio equipment.",
                    'logo' => null,
                    'website' => null,
                    'is_featured' => true,
                    'display_order' => $order++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // If no existing brands in products, create default flagship brands
            if ($existingBrands->isEmpty()) {
                $defaults = ['AETHER Studio', 'Sonix Labs', 'Vortex Custom', 'Nomad Vector', 'Lumina Tech', 'Chronos Labs'];
                foreach ($defaults as $brandName) {
                    DB::table('brands')->insert([
                        'name' => $brandName,
                        'slug' => Str::slug($brandName),
                        'description' => "Official manufacturer of {$brandName} equipment.",
                        'logo' => null,
                        'website' => null,
                        'is_featured' => true,
                        'display_order' => $order++,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Ignore seeder errors if table structure is being initialized
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
