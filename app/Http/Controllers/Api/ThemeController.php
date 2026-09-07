<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

class ThemeController extends Controller
{
    /**
     * Get public-facing storefront theme and UI settings.
     */
    public function publicSettings(): JsonResponse
    {
        $settings = Setting::all()->keyBy('key');

        $get = function (string $key, mixed $default = null) use ($settings) {
            if (!$settings->has($key)) {
                return $default;
            }
            $setting = $settings->get($key);
            return match ($setting->type) {
                'boolean' => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
                'number' => is_numeric($setting->value) ? (float) $setting->value : $default,
                'json' => json_decode($setting->value, true) ?? $default,
                default => $setting->value,
            };
        };

        $themeSettings = [
            'theme_primary_color' => $get('theme_primary_color', '#005826'),
            'theme_secondary_color' => $get('theme_secondary_color', '#2da54b'),
            'theme_accent_gradient' => $get('theme_accent_gradient', 'emerald-teal'),
            'theme_bg_color' => $get('theme_bg_color', '#ffffff'),
            'theme_card_bg_color' => $get('theme_card_bg_color', '#ffffff'),
            'theme_card_border_color' => $get('theme_card_border_color', '#e5e7eb'),
            'theme_text_heading_color' => $get('theme_text_heading_color', '#0f172a'),
            'theme_text_body_color' => $get('theme_text_body_color', '#475569'),
            'theme_btn_primary_bg' => $get('theme_btn_primary_bg', '#005826'),
            'theme_btn_primary_text' => $get('theme_btn_primary_text', '#ffffff'),
            'theme_btn_secondary_bg' => $get('theme_btn_secondary_bg', '#f3f4f6'),
            'theme_btn_secondary_text' => $get('theme_btn_secondary_text', '#0f172a'),
            'theme_tab_active_bg' => $get('theme_tab_active_bg', '#005826'),
            'theme_tab_active_text' => $get('theme_tab_active_text', '#ffffff'),
            'theme_hover_bg' => $get('theme_hover_bg', 'rgba(0, 88, 38, 0.08)'),
            'theme_hover_text' => $get('theme_hover_text', '#005826'),
            'theme_nav_btn_bg' => $get('theme_nav_btn_bg', '#ffffff'),
            'theme_nav_btn_color' => $get('theme_nav_btn_color', '#0f172a'),
            'theme_footer_bg_color' => $get('theme_footer_bg_color', '#0f172a'),
            'theme_footer_text_color' => $get('theme_footer_text_color', '#94a3b8'),
            'theme_radius' => $get('theme_radius', 'rounded-xl'),
            'announcement_enabled' => $get('announcement_enabled', true),
            'announcement_text' => $get('announcement_text', 'Free Express Courier on orders over ৳2,000 • Code: AETHER10 (-10%)'),
            'announcement_badge' => $get('announcement_badge', 'Fast Dispatch: Daily Courier Active'),
            'store_brand_name' => $brandName = $get('store_brand_name', 'INHALIQ'),
            'store_brand_tagline' => $get('store_brand_tagline', 'ELEVATE EVERY INHALE'),
            'store_brand_logo' => $get('store_brand_logo', ''),
            'hero_headline_line1' => $get('hero_headline_line1', 'Uncompromising'),
            'hero_headline_line2_gradient' => $get('hero_headline_line2_gradient', 'Industrial Audio'),
            'hero_headline_line3' => $get('hero_headline_line3', '& Tech Ecosystem.'),
            'hero_subheading' => $get('hero_subheading', 'Engineered with aerospace-grade titanium, custom beryllium drivers, and tactile mechanical acoustics for creators who refuse mediocrity.'),
            'hero_badge_text' => $get('hero_badge_text', '2026 Studio Flagship Release'),
            'split_reveal_enabled' => $get('split_reveal_enabled', true),
            'split_reveal_image' => $get('split_reveal_image', 'https://images.unsplash.com/photo-1550745165-9bc0b252726f?auto=format&fit=crop&w=2000&q=85'),
            'split_reveal_logo' => $get('split_reveal_logo', ''),
            'split_reveal_title' => ($get('split_reveal_title') && $get('split_reveal_title') !== 'AETHER') ? $get('split_reveal_title') : $brandName,
            'split_reveal_subtitle' => $get('split_reveal_subtitle', 'PRECISION ACOUSTICS & HARDWARE'),
            'split_reveal_duration' => (float) $get('split_reveal_duration', 2.2),
            'split_reveal_mode' => $get('split_reveal_mode', 'every_time'),
            'split_reveal_dim' => (float) $get('split_reveal_dim', 0.45),
            'split_reveal_direction' => $get('split_reveal_direction', 'vertical'),
        ];

        return response()->json($themeSettings);
    }
}
