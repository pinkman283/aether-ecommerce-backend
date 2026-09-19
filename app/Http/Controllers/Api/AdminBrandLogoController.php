<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BrandLogo;
use App\Models\BrandLogoPlacement;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminBrandLogoController extends Controller
{
    public const VALID_PLACEMENTS = [
        'navbar' => [
            'id' => 'navbar',
            'label' => 'Desktop Navbar',
            'description' => 'Main navigation header on desktop screens (≥640px)',
            'recommended' => '400 × 400 px · 1:1',
        ],
        'mobile_navbar' => [
            'id' => 'mobile_navbar',
            'label' => 'Mobile Navbar',
            'description' => 'Compact navigation header on mobile devices (<640px)',
            'recommended' => '320 × 320 px · 1:1',
        ],
        'footer' => [
            'id' => 'footer',
            'label' => 'Storefront Footer',
            'description' => 'Bottom branding emblem and copyright section',
            'recommended' => '500 × 500 px · 1:1',
        ],
        'auth' => [
            'id' => 'auth',
            'label' => 'Login / Registration',
            'description' => 'Customer authentication cards on /login and /signup',
            'recommended' => '400 × 400 px · 1:1',
        ],
        'invoice' => [
            'id' => 'invoice',
            'label' => 'Invoices & Receipts',
            'description' => 'Order confirmation invoices and printable receipts',
            'recommended' => '600 × 200 px · 3:1',
        ],
        'split_reveal' => [
            'id' => 'split_reveal',
            'label' => 'Splash / Split Screen',
            'description' => 'Centered split shutter reveal on homepage entrance',
            'recommended' => '512 × 512 px · 1:1',
        ],
    ];

    /**
     * List all brand logos, active placements, and favicon setting.
     */
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage', 'settings.manage');

        $logos = BrandLogo::with('placements')->orderBy('id', 'asc')->get();
        if ($logos->isEmpty()) {
            $legacyLogo = Setting::get('store_brand_logo', '');
            if (!empty($legacyLogo)) {
                $created = BrandLogo::create([
                    'name' => 'Primary Brand Mark',
                    'image_url' => $legacyLogo,
                ]);
                BrandLogoPlacement::create(['logo_id' => $created->id, 'placement' => 'navbar']);
                BrandLogoPlacement::create(['logo_id' => $created->id, 'placement' => 'footer']);
                BrandLogoPlacement::create(['logo_id' => $created->id, 'placement' => 'mobile_navbar']);
                $logos = BrandLogo::with('placements')->orderBy('id', 'asc')->get();
            }
        }

        $favicon = Setting::get('store_favicon', '');

        return response()->json([
            'logos' => $logos,
            'favicon' => $favicon,
            'available_placements' => array_values(self::VALID_PLACEMENTS),
        ]);
    }

    /**
     * Create a new brand logo with assigned placements.
     */
    public function store(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage', 'settings.manage');

        $validated = $request->validate([
            'image_url' => 'required|string',
            'name' => 'nullable|string|max:255',
            'placements' => 'nullable|array',
            'placements.*' => 'string|in:' . implode(',', array_keys(self::VALID_PLACEMENTS)),
        ]);

        $logo = DB::transaction(function () use ($validated, $request) {
            $logo = BrandLogo::create([
                'name' => !empty($validated['name']) ? trim($validated['name']) : null,
                'image_url' => trim($validated['image_url']),
            ]);

            $placements = $validated['placements'] ?? [];
            if (!empty($placements)) {
                // Reassign: delete existing assignments from any other logos
                BrandLogoPlacement::whereIn('placement', $placements)->delete();

                foreach ($placements as $placement) {
                    BrandLogoPlacement::create([
                        'logo_id' => $logo->id,
                        'placement' => $placement,
                    ]);
                }
            }

            $this->syncLegacySettings();
            Cache::forget('api_storefront_theme_settings');

            AuditLog::log(
                $request->user(),
                'brand_logo.created',
                'BrandLogo',
                $logo->id,
                "Created brand logo '{$logo->name}' with placements: " . implode(', ', $placements),
                null,
                $logo->toArray()
            );

            return $logo->load('placements');
        });

        return response()->json([
            'message' => 'Brand logo created successfully.',
            'logo' => $logo,
        ], 201);
    }

    /**
     * Update an existing brand logo and its placements.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage', 'settings.manage');

        $logo = BrandLogo::findOrFail($id);

        $validated = $request->validate([
            'image_url' => 'required|string',
            'name' => 'nullable|string|max:255',
            'placements' => 'nullable|array',
            'placements.*' => 'string|in:' . implode(',', array_keys(self::VALID_PLACEMENTS)),
        ]);

        $updatedLogo = DB::transaction(function () use ($logo, $validated, $request) {
            $oldValues = $logo->load('placements')->toArray();

            $logo->update([
                'name' => !empty($validated['name']) ? trim($validated['name']) : null,
                'image_url' => trim($validated['image_url']),
            ]);

            $requestedPlacements = $validated['placements'] ?? [];

            // 1. Remove requested placements from OTHER logos (conflict resolution)
            BrandLogoPlacement::whereIn('placement', $requestedPlacements)
                ->where('logo_id', '!=', $logo->id)
                ->delete();

            // 2. Remove placements unselected for THIS logo
            BrandLogoPlacement::where('logo_id', $logo->id)
                ->whereNotIn('placement', $requestedPlacements)
                ->delete();

            // 3. Add newly assigned placements
            foreach ($requestedPlacements as $placement) {
                BrandLogoPlacement::firstOrCreate([
                    'logo_id' => $logo->id,
                    'placement' => $placement,
                ]);
            }

            $this->syncLegacySettings();
            Cache::forget('api_storefront_theme_settings');

            AuditLog::log(
                $request->user(),
                'brand_logo.updated',
                'BrandLogo',
                $logo->id,
                "Updated brand logo '{$logo->name}' with placements: " . implode(', ', $requestedPlacements),
                $oldValues,
                $logo->load('placements')->toArray()
            );

            return $logo->load('placements');
        });

        return response()->json([
            'message' => 'Brand logo updated successfully.',
            'logo' => $updatedLogo,
        ]);
    }

    /**
     * Batch update placements across multiple brand logos.
     */
    public function batchUpdatePlacements(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage', 'settings.manage');

        $validated = $request->validate([
            'placements' => 'required|array',
            'placements.*.logo_id' => 'required|integer|exists:brand_logos,id',
            'placements.*.placements' => 'present|array',
            'placements.*.placements.*' => 'string|in:' . implode(',', array_keys(self::VALID_PLACEMENTS)),
        ]);

        DB::transaction(function () use ($validated, $request) {
            $logoIds = array_column($validated['placements'], 'logo_id');

            // Delete existing placements for all targeted logos
            BrandLogoPlacement::whereIn('logo_id', $logoIds)->delete();

            $assignedSurfaces = [];

            foreach ($validated['placements'] as $item) {
                $logoId = $item['logo_id'];
                $requested = array_unique($item['placements'] ?? []);

                foreach ($requested as $placementKey) {
                    // Ensure each surface placement belongs to at most one logo mark
                    if (isset($assignedSurfaces[$placementKey])) {
                        continue;
                    }
                    $assignedSurfaces[$placementKey] = $logoId;

                    // Clear this placement if it was on any other logo
                    BrandLogoPlacement::where('placement', $placementKey)->delete();

                    BrandLogoPlacement::create([
                        'logo_id' => $logoId,
                        'placement' => $placementKey,
                    ]);
                }
            }

            $this->syncLegacySettings();
            Cache::forget('api_storefront_theme_settings');

            AuditLog::log(
                $request->user(),
                'brand_logo.batch_placements_updated',
                'BrandLogoPlacement',
                null,
                "Updated brand logo placements in batch across " . count($logoIds) . " logos.",
                null,
                $validated['placements']
            );
        });

        $logos = BrandLogo::with('placements')->orderBy('id', 'asc')->get();

        return response()->json([
            'message' => 'Brand logo placements saved successfully.',
            'logos' => $logos,
        ]);
    }

    /**
     * Delete a brand logo.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage', 'settings.manage');

        $logo = BrandLogo::findOrFail($id);

        DB::transaction(function () use ($logo, $request) {
            $oldValues = $logo->load('placements')->toArray();
            $logoName = $logo->name ?: 'Brand Logo #' . $logo->id;

            $logo->delete(); // Cascades placements
            $this->syncLegacySettings();
            Cache::forget('api_storefront_theme_settings');

            AuditLog::log(
                $request->user(),
                'brand_logo.deleted',
                'BrandLogo',
                $logo->id,
                "Deleted brand logo '{$logoName}'",
                $oldValues,
                null
            );
        });

        return response()->json([
            'message' => 'Brand logo removed successfully.',
        ]);
    }

    /**
     * Upload an image file for branding assets (logos or favicon).
     */
    public function upload(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage', 'settings.manage');

        $request->validate([
            'image' => 'required|file|mimes:jpeg,png,jpg,webp,gif,ico,avif|max:10240',
        ]);

        $file = $request->file('image');
        $ext = $file->getClientOriginalExtension() ?: 'png';
        $filename = 'branding_' . Str::random(16) . '_' . time() . '.' . $ext;
        $path = $file->storeAs('branding', $filename, 'public');

        return response()->json([
            'message' => 'Branding asset uploaded successfully.',
            'image_url' => Storage::disk('public')->url($path),
            'path' => $path,
        ]);
    }

    /**
     * Update the separate store favicon setting.
     */
    public function updateFavicon(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage', 'settings.manage');

        $validated = $request->validate([
            'favicon_url' => 'nullable|string',
        ]);

        $faviconUrl = trim($validated['favicon_url'] ?? '');

        // Physically delete previous favicon file if it changed
        $oldFavicon = Setting::get('store_favicon');
        if (!empty($oldFavicon) && $oldFavicon !== $faviconUrl) {
            $this->deleteBrandingStorageFile($oldFavicon);
        }

        Setting::set('store_favicon', $faviconUrl, 'branding', 'string', 'Store Favicon');
        Cache::forget('api_storefront_theme_settings');

        AuditLog::log(
            $request->user(),
            'branding.favicon_updated',
            'Setting',
            null,
            $faviconUrl ? "Updated store browser favicon" : "Removed store browser favicon",
            ['store_favicon' => $oldFavicon],
            ['store_favicon' => $faviconUrl]
        );

        return response()->json([
            'message' => $faviconUrl ? 'Favicon updated successfully.' : 'Favicon removed.',
            'favicon' => $faviconUrl,
        ]);
    }

    /**
     * Remove the separate store favicon setting.
     */
    public function removeFavicon(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage', 'settings.manage');

        $oldFavicon = Setting::get('store_favicon');
        if (!empty($oldFavicon)) {
            $this->deleteBrandingStorageFile($oldFavicon);
        }

        Setting::set('store_favicon', '', 'branding', 'string', 'Store Favicon');
        Cache::forget('api_storefront_theme_settings');

        AuditLog::log(
            $request->user(),
            'branding.favicon_removed',
            'Setting',
            null,
            "Removed store browser favicon",
            ['store_favicon' => $oldFavicon],
            ['store_favicon' => '']
        );

        return response()->json([
            'message' => 'Favicon removed successfully.',
            'favicon' => '',
        ]);
    }

    /**
     * Physically remove previous branding storage file if it exists on the public disk.
     */
    private function deleteBrandingStorageFile(?string $url): void
    {
        if (empty($url)) {
            return;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) {
            $path = $url;
        }

        $path = explode('?', $path)[0];
        $storagePrefix = '/storage/';
        if (str_starts_with($path, $storagePrefix)) {
            $relPath = substr($path, strlen($storagePrefix));
        } elseif (str_starts_with($path, 'branding/')) {
            $relPath = $path;
        } else {
            $filename = basename($path);
            $relPath = 'branding/' . $filename;
        }

        if (!empty($relPath) && Storage::disk('public')->exists($relPath)) {
            Storage::disk('public')->delete($relPath);
        }
    }

    /**
     * Keep legacy store_brand_logo and split_reveal_logo synchronized as fallbacks.
     */
    private function syncLegacySettings(): void
    {
        // Active navbar logo becomes primary fallback
        $navPlacement = BrandLogoPlacement::where('placement', 'navbar')->with('logo')->first();
        if ($navPlacement && $navPlacement->logo) {
            Setting::set('store_brand_logo', $navPlacement->logo->image_url, 'theme', 'string', 'Store Brand Logo');
        } else {
            // Fallback to first available logo or empty
            $firstLogo = BrandLogo::first();
            Setting::set('store_brand_logo', $firstLogo ? $firstLogo->image_url : '', 'theme', 'string', 'Store Brand Logo');
        }

        // Active split_reveal logo
        $splitPlacement = BrandLogoPlacement::where('placement', 'split_reveal')->with('logo')->first();
        if ($splitPlacement && $splitPlacement->logo) {
            Setting::set('split_reveal_logo', $splitPlacement->logo->image_url, 'theme', 'string', 'Split Reveal Logo');
        }
    }
}
