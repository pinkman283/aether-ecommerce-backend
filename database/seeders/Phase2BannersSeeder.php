<?php

namespace Database\Seeders;

use App\Models\Banner;
use Illuminate\Database\Seeder;

class Phase2BannersSeeder extends Seeder
{
    public function run(): void
    {
        $banners = [
            [
                'title' => 'Aether Pulse ANC Wireless Studio',
                'subtitle' => 'Next-Gen 50mm Beryllium Transducers. Pure Lossless Soundscapes.',
                'image_url' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=1600&q=85',
                'cta_text' => 'Explore Flagship',
                'cta_link' => '/products/aether-pulse-anc-wireless-headphones',
                'placement' => 'hero_slider',
                'badge' => 'NEW ARRIVAL',
                'discount_tag' => '20% OFF PRE-ORDER',
                'sort_order' => 1,
                'is_active' => true,
                'clicks_count' => 142,
                'impressions_count' => 3820,
            ],
            [
                'title' => 'Vortex 75 CNC Gasket Mechanical Keyboard',
                'subtitle' => 'Anodized 6063 Aluminum Chassis with Five-Layer Acoustic Dampening.',
                'image_url' => 'https://images.unsplash.com/photo-1587829741301-dc798b83add3?auto=format&fit=crop&w=1600&q=85',
                'cta_text' => 'Configure Keeb',
                'cta_link' => '/products/vortex-75-cnc-gasket-mechanical-keyboard',
                'placement' => 'hero_slider',
                'badge' => 'LIMITED DROP',
                'discount_tag' => null,
                'sort_order' => 2,
                'is_active' => true,
                'clicks_count' => 98,
                'impressions_count' => 2410,
            ],
            [
                'title' => 'Worldwide Express Courier Free on Orders over $100 — Use Code FREESHIP',
                'subtitle' => 'Dispatched same-day from Ohio & Frankfurt fulfillment hubs.',
                'image_url' => 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=1200&q=85',
                'cta_text' => 'Shop All Gear',
                'cta_link' => '/shop',
                'placement' => 'top_announcement',
                'badge' => 'PROMO',
                'discount_tag' => null,
                'sort_order' => 1,
                'is_active' => true,
                'clicks_count' => 64,
                'impressions_count' => 5200,
            ],
            [
                'title' => 'Chronos Titan Ultra Aerospace Edition',
                'subtitle' => 'Biometrics ECG, dual-frequency GPS, and 14-day expedition battery.',
                'image_url' => 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?auto=format&fit=crop&w=1200&q=85',
                'cta_text' => 'Discover Titan',
                'cta_link' => '/products/chronos-titan-ultra-smartwatch',
                'placement' => 'middle_promo',
                'badge' => 'TITANIUM',
                'discount_tag' => null,
                'sort_order' => 1,
                'is_active' => true,
                'clicks_count' => 87,
                'impressions_count' => 1890,
            ],
        ];

        foreach ($banners as $b) {
            Banner::firstOrCreate(['title' => $b['title'], 'placement' => $b['placement']], $b);
        }
    }
}
