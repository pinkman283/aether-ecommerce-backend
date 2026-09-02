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
        $themeSettings = [
            'theme_primary_color' => Setting::get('theme_primary_color', '#06b6d4'),
            'theme_secondary_color' => Setting::get('theme_secondary_color', '#6366f1'),
            'theme_accent_gradient' => Setting::get('theme_accent_gradient', 'cyan-indigo'),
            'theme_bg_color' => Setting::get('theme_bg_color', '#090a0f'),
            'theme_card_bg_color' => Setting::get('theme_card_bg_color', '#0c101d'),
            'theme_card_border_color' => Setting::get('theme_card_border_color', 'rgba(255, 255, 255, 0.1)'),
            'theme_text_heading_color' => Setting::get('theme_text_heading_color', '#ffffff'),
            'theme_text_body_color' => Setting::get('theme_text_body_color', '#94a3b8'),
            'theme_btn_primary_bg' => Setting::get('theme_btn_primary_bg', '#06b6d4'),
            'theme_btn_primary_text' => Setting::get('theme_btn_primary_text', '#ffffff'),
            'theme_btn_secondary_bg' => Setting::get('theme_btn_secondary_bg', 'rgba(255, 255, 255, 0.05)'),
            'theme_btn_secondary_text' => Setting::get('theme_btn_secondary_text', '#ffffff'),
            'theme_tab_active_bg' => Setting::get('theme_tab_active_bg', '#06b6d4'),
            'theme_tab_active_text' => Setting::get('theme_tab_active_text', '#ffffff'),
            'theme_hover_bg' => Setting::get('theme_hover_bg', 'rgba(6, 182, 212, 0.15)'),
            'theme_hover_text' => Setting::get('theme_hover_text', '#06b6d4'),
            'theme_nav_btn_bg' => Setting::get('theme_nav_btn_bg', '#0c101d'),
            'theme_nav_btn_color' => Setting::get('theme_nav_btn_color', '#ffffff'),
            'theme_radius' => Setting::get('theme_radius', 'rounded-lg'),
            'announcement_enabled' => Setting::get('announcement_enabled', true),
            'announcement_text' => Setting::get('announcement_text', 'Free Express Shipping on orders over $100 • Code: WELCOME20 (-20%)'),
            'announcement_badge' => Setting::get('announcement_badge', 'Studio Dispatch: Global Shipping Active'),
            'store_brand_name' => Setting::get('store_brand_name', 'AETHER'),
            'store_brand_tagline' => Setting::get('store_brand_tagline', 'Studio & Lab'),
            'store_brand_logo' => Setting::get('store_brand_logo', ''),
            'hero_headline_line1' => Setting::get('hero_headline_line1', 'Uncompromising'),
            'hero_headline_line2_gradient' => Setting::get('hero_headline_line2_gradient', 'Industrial Audio'),
            'hero_headline_line3' => Setting::get('hero_headline_line3', '& Tech Ecosystem.'),
            'hero_subheading' => Setting::get('hero_subheading', 'Engineered with aerospace-grade titanium, custom beryllium drivers, and tactile mechanical acoustics for creators who refuse mediocrity.'),
            'hero_badge_text' => Setting::get('hero_badge_text', '2026 Studio Flagship Release'),
            'split_reveal_enabled' => Setting::get('split_reveal_enabled', true),
            'split_reveal_image' => Setting::get('split_reveal_image', 'https://images.unsplash.com/photo-1550745165-9bc0b252726f?auto=format&fit=crop&w=2000&q=85'),
            'split_reveal_logo' => Setting::get('split_reveal_logo', ''),
            'split_reveal_title' => Setting::get('split_reveal_title', 'AETHER'),
            'split_reveal_subtitle' => Setting::get('split_reveal_subtitle', 'PRECISION ACOUSTICS & HARDWARE'),
            'split_reveal_duration' => (float) Setting::get('split_reveal_duration', 2.2),
            'split_reveal_mode' => Setting::get('split_reveal_mode', 'every_time'),
            'split_reveal_dim' => (float) Setting::get('split_reveal_dim', 0.45),
            'split_reveal_direction' => Setting::get('split_reveal_direction', 'vertical'),
        ];

        return response()->json($themeSettings);
    }
}
