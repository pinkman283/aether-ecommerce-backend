<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminThemeController extends Controller
{
    private array $defaults = [
        'theme_primary_color' => '#06b6d4',
        'theme_secondary_color' => '#6366f1',
        'theme_accent_gradient' => 'cyan-indigo',
        'theme_bg_color' => '#090a0f',
        'theme_card_bg_color' => '#0c101d',
        'theme_card_border_color' => 'rgba(255, 255, 255, 0.1)',
        'theme_text_heading_color' => '#ffffff',
        'theme_text_body_color' => '#94a3b8',
        'theme_btn_primary_bg' => '#06b6d4',
        'theme_btn_primary_text' => '#ffffff',
        'theme_btn_secondary_bg' => 'rgba(255, 255, 255, 0.05)',
        'theme_btn_secondary_text' => '#ffffff',
        'theme_tab_active_bg' => '#06b6d4',
        'theme_tab_active_text' => '#ffffff',
        'theme_view_all_color' => '#06b6d4',
        'theme_hover_bg' => 'rgba(6, 182, 212, 0.15)',
        'theme_hover_text' => '#06b6d4',
        'theme_nav_btn_bg' => '#0c101d',
        'theme_nav_btn_color' => '#ffffff',
        'theme_footer_bg_color' => '#1f242e',
        'theme_footer_text_color' => '#94a3b8',
        'theme_radius' => 'rounded-lg',
        'announcement_enabled' => true,
        'announcement_text' => 'Free Express Shipping on orders over $100 • Code: WELCOME20 (-20%)',
        'announcement_badge' => 'Studio Dispatch: Global Shipping Active',
        'store_brand_name' => 'AETHER',
        'store_brand_tagline' => 'Studio & Lab',
        'store_brand_logo' => '',
        'hero_headline_line1' => 'Uncompromising',
        'hero_headline_line2_gradient' => 'Industrial Audio',
        'hero_headline_line3' => '& Tech Ecosystem.',
        'hero_subheading' => 'Engineered with aerospace-grade titanium, custom beryllium drivers, and tactile mechanical acoustics for creators who refuse mediocrity.',
        'hero_badge_text' => '2026 Studio Flagship Release',
        'split_reveal_enabled' => true,
        'split_reveal_image' => 'https://images.unsplash.com/photo-1550745165-9bc0b252726f?auto=format&fit=crop&w=2000&q=85',
        'split_reveal_logo' => '',
        'split_reveal_title' => 'AETHER',
        'split_reveal_subtitle' => 'PRECISION ACOUSTICS & HARDWARE',
        'split_reveal_duration' => 2.2,
        'split_reveal_mode' => 'every_time',
        'split_reveal_dim' => 0.45,
        'split_reveal_direction' => 'vertical',
        'nav_deals_enabled' => true,
        'nav_deals_text' => 'Deals',
        'nav_deals_link' => '/products?discounted=true',
        'category_deals_card_enabled' => true,
        'category_deals_card_title' => 'Top Deals',
        'category_deals_card_subtitle' => 'Up to 20% Off',
        'category_deals_card_link' => '/products?discounted=true',
        'footer_features_enabled' => true,
        'footer_feature_title_1' => 'Free Express Shipping',
        'footer_feature_desc_1' => 'Complimentary delivery inside & outside Dhaka.',
        'footer_feature_link_1' => '/shipping-policy',
        'footer_feature_title_2' => '2-Year Studio Warranty',
        'footer_feature_desc_2' => 'Comprehensive hardware protection & zero-cost repair.',
        'footer_feature_link_2' => '/refund-policy',
        'footer_feature_title_3' => '30-Day Risk-Free Trial',
        'footer_feature_desc_3' => 'Hassle-free evaluation with prepaid RMA labels.',
        'footer_feature_link_3' => '/refund-policy',
        'footer_feature_title_4' => '24/7 Audio Support',
        'footer_feature_desc_4' => 'Direct access to sound engineers & hardware specialists.',
        'footer_feature_link_4' => '/contact',
        'customer_auth_bg_image' => '',
        'customer_auth_bg_color' => '#ffffff',
        'customer_auth_card_position' => 'left',
        'theme_default_preset' => 'cyan-indigo',
        'flash_deals_enabled' => true,
        'flash_deals_title' => 'Limited Time Deals',
        'flash_deals_badge' => 'Flash Deal Drop',
        'trust_ribbon_enabled' => true,
        'trust_ribbon_title_1' => 'Fast Express Delivery',
        'trust_ribbon_desc_1' => 'Dispatched within 24-48 hours',
        'trust_ribbon_title_2' => 'Cash on Delivery (COD)',
        'trust_ribbon_desc_2' => 'Pay safely upon product arrival',
        'trust_ribbon_title_3' => '100% Genuine & Authentic',
        'trust_ribbon_desc_3' => 'Official manufacturer warranty coverage',
        'trust_ribbon_title_4' => '7-Day Easy Replacement',
        'trust_ribbon_desc_4' => 'Hassle-free returns & replacement policy',
        'deleted_theme_ids' => [],
    ];

    /**
     * Fetch theme settings for administrative editing.
     */
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage', 'settings.manage');

        $settings = [];
        foreach ($this->defaults as $key => $defaultVal) {
            $settings[$key] = Setting::get($key, $defaultVal);
        }

        $customThemes = Setting::get('custom_themes', []);
        if (is_string($customThemes)) {
            $customThemes = json_decode($customThemes, true) ?: [];
        }
        $settings['custom_themes'] = $customThemes;

        $deletedThemeIds = Setting::get('deleted_theme_ids', []);
        if (is_string($deletedThemeIds)) {
            $deletedThemeIds = json_decode($deletedThemeIds, true) ?: [];
        }
        $settings['deleted_theme_ids'] = $deletedThemeIds;

        return response()->json([
            'settings' => $settings,
            'defaults' => $this->defaults,
        ]);
    }

    /**
     * Update theme and storefront UI customization settings.
     */
    public function update(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage', 'settings.manage');

        $validated = $request->validate([
            'theme_primary_color' => 'nullable|string|max:30',
            'theme_secondary_color' => 'nullable|string|max:30',
            'theme_accent_gradient' => 'nullable|string|max:50',
            'theme_bg_color' => 'nullable|string|max:30',
            'theme_card_bg_color' => 'nullable|string|max:30',
            'theme_card_border_color' => 'nullable|string|max:80',
            'theme_text_heading_color' => 'nullable|string|max:50',
            'theme_text_body_color' => 'nullable|string|max:50',
            'theme_btn_primary_bg' => 'nullable|string|max:50',
            'theme_btn_primary_text' => 'nullable|string|max:50',
            'theme_btn_secondary_bg' => 'nullable|string|max:80',
            'theme_btn_secondary_text' => 'nullable|string|max:50',
            'theme_tab_active_bg' => 'nullable|string|max:50',
            'theme_tab_active_text' => 'nullable|string|max:50',
            'theme_view_all_color' => 'nullable|string|max:50',
            'theme_hover_bg' => 'nullable|string|max:80',
            'theme_hover_text' => 'nullable|string|max:50',
            'theme_nav_btn_bg' => 'nullable|string|max:50',
            'theme_nav_btn_color' => 'nullable|string|max:50',
            'theme_footer_bg_color' => 'nullable|string|max:50',
            'theme_footer_text_color' => 'nullable|string|max:50',
            'theme_radius' => 'nullable|string|in:rounded-none,rounded-md,rounded-lg,rounded-xl,rounded-2xl',
            'announcement_enabled' => 'nullable|boolean',
            'announcement_text' => 'nullable|string|max:500',
            'announcement_badge' => 'nullable|string|max:200',
            'navbar_promo_enabled' => 'nullable|boolean',
            'navbar_promo_discount_text' => 'nullable|string|max:50',
            'navbar_promo_code' => 'nullable|string|max:50',
            'navbar_promo_link' => 'nullable|string|max:200',
            'store_brand_name' => 'nullable|string|max:100',
            'store_brand_tagline' => 'nullable|string|max:150',
            'store_brand_logo' => 'nullable|string',
            'hero_headline_line1' => 'nullable|string|max:150',
            'hero_headline_line2_gradient' => 'nullable|string|max:150',
            'hero_headline_line3' => 'nullable|string|max:150',
            'hero_subheading' => 'nullable|string|max:1000',
            'hero_badge_text' => 'nullable|string|max:150',
            'split_reveal_enabled' => 'nullable|boolean',
            'split_reveal_image' => 'nullable|string',
            'split_reveal_logo' => 'nullable|string',
            'split_reveal_title' => 'nullable|string|max:100',
            'split_reveal_subtitle' => 'nullable|string|max:200',
            'split_reveal_duration' => 'nullable|numeric|min:0.5|max:10.0',
            'split_reveal_mode' => 'nullable|string|in:every_time,once_per_session',
            'split_reveal_dim' => 'nullable|numeric|min:0|max:1',
            'split_reveal_direction' => 'nullable|string|in:vertical,horizontal',
            'nav_deals_enabled' => 'nullable|boolean',
            'nav_deals_text' => 'nullable|string|max:50',
            'nav_deals_link' => 'nullable|string|max:200',
            'category_deals_card_enabled' => 'nullable|boolean',
            'category_deals_card_title' => 'nullable|string|max:50',
            'category_deals_card_subtitle' => 'nullable|string|max:100',
            'category_deals_card_link' => 'nullable|string|max:200',
            'footer_features_enabled' => 'nullable|boolean',
            'footer_feature_title_1' => 'nullable|string|max:100',
            'footer_feature_desc_1' => 'nullable|string|max:200',
            'footer_feature_link_1' => 'nullable|string|max:200',
            'footer_feature_title_2' => 'nullable|string|max:100',
            'footer_feature_desc_2' => 'nullable|string|max:200',
            'footer_feature_link_2' => 'nullable|string|max:200',
            'footer_feature_title_3' => 'nullable|string|max:100',
            'footer_feature_desc_3' => 'nullable|string|max:200',
            'footer_feature_link_3' => 'nullable|string|max:200',
            'footer_feature_title_4' => 'nullable|string|max:100',
            'footer_feature_desc_4' => 'nullable|string|max:200',
            'footer_feature_link_4' => 'nullable|string|max:200',
            'customer_auth_bg_image' => 'nullable|string',
            'customer_auth_bg_color' => 'nullable|string|max:30',
            'customer_auth_card_position' => 'nullable|string|in:left,center,right',
            'theme_default_preset' => 'nullable|string|max:50',
            'flash_deals_enabled' => 'nullable|boolean',
            'flash_deals_title' => 'nullable|string|max:100',
            'flash_deals_badge' => 'nullable|string|max:100',
            'trust_ribbon_enabled' => 'nullable|boolean',
            'trust_ribbon_title_1' => 'nullable|string|max:100',
            'trust_ribbon_desc_1' => 'nullable|string|max:200',
            'trust_ribbon_title_2' => 'nullable|string|max:100',
            'trust_ribbon_desc_2' => 'nullable|string|max:200',
            'trust_ribbon_title_3' => 'nullable|string|max:100',
            'trust_ribbon_desc_3' => 'nullable|string|max:200',
            'trust_ribbon_title_4' => 'nullable|string|max:100',
            'trust_ribbon_desc_4' => 'nullable|string|max:200',
            'custom_themes' => 'nullable',
            'deleted_theme_ids' => 'nullable',
        ]);

        $oldValues = [];
        $newValues = [];

        foreach ($validated as $key => $value) {
            if ($key === 'custom_themes') {
                $oldValues['custom_themes'] = Setting::get('custom_themes', []);
                Setting::set('custom_themes', is_array($value) ? json_encode($value) : $value, 'theme', 'json', 'Custom Saved Themes');
                $newValues['custom_themes'] = $value;
                continue;
            }

            if ($key === 'deleted_theme_ids') {
                $oldValues['deleted_theme_ids'] = Setting::get('deleted_theme_ids', []);
                Setting::set('deleted_theme_ids', is_array($value) ? json_encode($value) : $value, 'theme', 'json', 'Deleted Theme IDs');
                $newValues['deleted_theme_ids'] = $value;
                continue;
            }

            if ($request->has($key)) {
                $oldValues[$key] = Setting::get($key, $this->defaults[$key] ?? null);
                
                $valToSave = $value ?? '';
                // If an image was submitted as a Base64 Data URL, persist to disk for ultra-fast serving & SSR hydration safety
                if (is_string($valToSave) && preg_match('/^data:image\/(\w+);base64,/', $valToSave, $matches)) {
                    $ext = strtolower($matches[1]);
                    if ($ext === 'jpeg') $ext = 'jpg';
                    $data = base64_decode(substr($valToSave, strpos($valToSave, ',') + 1));
                    $filename = 'branding_' . Str::slug($key) . '_' . time() . '.' . $ext;
                    Storage::disk('public')->put('branding/' . $filename, $data);
                    $valToSave = url('storage/branding/' . $filename);
                }

                $type = is_bool($valToSave) ? 'boolean' : (is_numeric($valToSave) ? 'number' : 'string');
                Setting::set($key, $valToSave, 'theme', $type, ucwords(str_replace('_', ' ', $key)));
                
                $newValues[$key] = $valToSave;
            }
        }

        // When store_brand_name is updated, synchronize store_name and split_reveal_title across the site
        if (isset($newValues['store_brand_name']) && !empty($newValues['store_brand_name'])) {
            $brand = $newValues['store_brand_name'];
            Setting::set('store_name', $brand, 'general', 'string', 'Store Name');

            $currentSplitTitle = Setting::get('split_reveal_title', 'AETHER');
            $oldBrandName = $oldValues['store_brand_name'] ?? 'AETHER';
            if (!isset($newValues['split_reveal_title']) || $newValues['split_reveal_title'] === 'AETHER' || $newValues['split_reveal_title'] === $oldBrandName) {
                Setting::set('split_reveal_title', $brand, 'theme', 'string', 'Split Reveal Title');
                $newValues['split_reveal_title'] = $brand;
            }
        }

        AuditLog::log(
            $request->user(),
            'theme.updated',
            'ThemeSetting',
            null,
            "Updated storefront visual styling, theme colors, and UI properties.",
            $oldValues,
            $newValues
        );

        $currentSettings = [];
        foreach ($this->defaults as $key => $defaultVal) {
            $currentSettings[$key] = Setting::get($key, $defaultVal);
        }
        $customThemes = Setting::get('custom_themes', []);
        if (is_string($customThemes)) {
            $customThemes = json_decode($customThemes, true) ?: [];
        }
        $currentSettings['custom_themes'] = $customThemes;

        $deletedThemeIds = Setting::get('deleted_theme_ids', []);
        if (is_string($deletedThemeIds)) {
            $deletedThemeIds = json_decode($deletedThemeIds, true) ?: [];
        }
        $currentSettings['deleted_theme_ids'] = $deletedThemeIds;

        return response()->json([
            'message' => 'Storefront theme and UI settings updated successfully.',
            'settings' => $currentSettings,
        ]);
    }

    /**
     * Reset theme configurations to factory defaults.
     */
    public function resetDefaults(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage', 'settings.manage');

        $oldValues = [];
        foreach ($this->defaults as $key => $defaultVal) {
            $oldValues[$key] = Setting::get($key, $defaultVal);
            $type = is_bool($defaultVal) ? 'boolean' : (is_numeric($defaultVal) ? 'number' : 'string');
            Setting::set($key, $defaultVal, 'theme', $type, ucwords(str_replace('_', ' ', $key)));
        }

        AuditLog::log(
            $request->user(),
            'theme.reset',
            'ThemeSetting',
            null,
            "Reset storefront visual theme to factory defaults.",
            $oldValues,
            $this->defaults
        );

        return response()->json([
            'message' => 'Theme settings restored to factory defaults.',
            'settings' => $this->defaults,
        ]);
    }
}
