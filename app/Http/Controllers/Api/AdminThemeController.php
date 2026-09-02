<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        'theme_hover_bg' => 'rgba(6, 182, 212, 0.15)',
        'theme_hover_text' => '#06b6d4',
        'theme_nav_btn_bg' => '#0c101d',
        'theme_nav_btn_color' => '#ffffff',
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
            'theme_hover_bg' => 'nullable|string|max:80',
            'theme_hover_text' => 'nullable|string|max:50',
            'theme_nav_btn_bg' => 'nullable|string|max:50',
            'theme_nav_btn_color' => 'nullable|string|max:50',
            'theme_radius' => 'nullable|string|in:rounded-none,rounded-md,rounded-lg,rounded-xl,rounded-2xl',
            'announcement_enabled' => 'nullable|boolean',
            'announcement_text' => 'nullable|string|max:500',
            'announcement_badge' => 'nullable|string|max:200',
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
            'custom_themes' => 'nullable',
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

            if ($value !== null) {
                $oldValues[$key] = Setting::get($key, $this->defaults[$key] ?? null);
                
                $type = is_bool($value) ? 'boolean' : (is_numeric($value) ? 'number' : 'string');
                Setting::set($key, $value, 'theme', $type, ucwords(str_replace('_', ' ', $key)));
                
                $newValues[$key] = $value;
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
