<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\HomepageSection;
use Illuminate\Database\Seeder;

class HomepageSectionsSeeder extends Seeder
{
    public function run(): void
    {
        $sections = [
            [
                'title' => 'Flagship Audio & Acoustics',
                'slug' => 'flagship-audio-acoustics',
                'subtitle' => 'Audiophile-grade studio monitors, active noise-cancelling headphones, and wireless planar earbuds.',
                'badge_text' => 'HI-RES LOSSLESS',
                'badge_icon' => 'Headphones',
                'is_active' => true,
                'sort_order' => 0,
                'product_count' => 14,
                'view_all_label' => 'View All',
                'view_all_url' => '/products?category=audio-acoustics',
                'has_tabs' => true,
                'category_slug' => 'audio-acoustics',
                'source_type' => 'category',
                'sort_by' => 'featured',
            ],
            [
                'title' => 'Mechanical Keyboards & Desks',
                'slug' => 'mechanical-keyboards-desks',
                'subtitle' => 'Custom gasket-mounted keyboards, lubricated switches, CNC aluminum cases, and artisan desk mats.',
                'badge_text' => 'TACTILE ACOUSTICS',
                'badge_icon' => 'Keyboard',
                'is_active' => true,
                'sort_order' => 1,
                'product_count' => 14,
                'view_all_label' => 'View All',
                'view_all_url' => '/products?category=keyboards-desks',
                'has_tabs' => true,
                'category_slug' => 'keyboards-desks',
                'source_type' => 'category',
                'sort_by' => 'featured',
            ],
            [
                'title' => 'Everyday Carry & Tech Packs',
                'slug' => 'everyday-carry-tech-packs',
                'subtitle' => 'Aerospace titanium tools, weatherproof tech pouches, magnetic key organizers, and minimal wallets.',
                'badge_text' => 'TACTICAL UTILITY',
                'badge_icon' => 'Briefcase',
                'is_active' => true,
                'sort_order' => 2,
                'product_count' => 14,
                'view_all_label' => 'View All',
                'view_all_url' => '/products?category=everyday-carry',
                'has_tabs' => true,
                'category_slug' => 'everyday-carry',
                'source_type' => 'category',
                'sort_by' => 'featured',
            ],
            [
                'title' => 'Smart Living & Ambient Lighting',
                'slug' => 'smart-living-ambient-lighting',
                'subtitle' => 'RGB desktop lightbars, acoustic wall panels, smart desk chargers, and wireless charging pads.',
                'badge_text' => 'AMBIENT ECOSYSTEM',
                'badge_icon' => 'Sparkles',
                'is_active' => true,
                'sort_order' => 3,
                'product_count' => 14,
                'view_all_label' => 'View All',
                'view_all_url' => '/products?category=smart-living-lighting',
                'has_tabs' => true,
                'category_slug' => 'smart-living-lighting',
                'source_type' => 'category',
                'sort_by' => 'featured',
            ],
            [
                'title' => 'Pro Cyber Wearables',
                'slug' => 'pro-cyber-wearables',
                'subtitle' => 'Titanium smart rings, biometric fitness trackers, and curved high-refresh studio displays.',
                'badge_text' => 'PRECISION SENSING',
                'badge_icon' => 'Watch',
                'is_active' => true,
                'sort_order' => 4,
                'product_count' => 14,
                'view_all_label' => 'View All',
                'view_all_url' => '/products?category=pro-wearables',
                'has_tabs' => true,
                'category_slug' => 'pro-wearables',
                'source_type' => 'category',
                'sort_by' => 'featured',
            ],
        ];

        foreach ($sections as $data) {
            $cat = Category::where('slug', $data['category_slug'])->first();
            $categoryId = $cat ? $cat->id : null;

            $tabs = [
                [
                    'id' => 'tab_featured_' . $data['slug'],
                    'name' => 'Featured',
                    'source_type' => 'category',
                    'category_id' => $categoryId,
                    'brand_id' => null,
                    'sort_by' => 'featured',
                    'product_ids' => [],
                ],
                [
                    'id' => 'tab_new_' . $data['slug'],
                    'name' => 'New Arrivals',
                    'source_type' => 'category',
                    'category_id' => $categoryId,
                    'brand_id' => null,
                    'sort_by' => 'newest',
                    'product_ids' => [],
                ],
                [
                    'id' => 'tab_bestsellers_' . $data['slug'],
                    'name' => 'Best Sellers',
                    'source_type' => 'category',
                    'category_id' => $categoryId,
                    'brand_id' => null,
                    'sort_by' => 'best_selling',
                    'product_ids' => [],
                ],
            ];

            HomepageSection::updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'title' => $data['title'],
                    'subtitle' => $data['subtitle'],
                    'badge_text' => $data['badge_text'],
                    'badge_icon' => $data['badge_icon'],
                    'is_active' => $data['is_active'],
                    'sort_order' => $data['sort_order'],
                    'product_count' => $data['product_count'],
                    'view_all_label' => $data['view_all_label'],
                    'view_all_url' => $data['view_all_url'],
                    'has_tabs' => $data['has_tabs'],
                    'tabs' => $tabs,
                    'source_type' => $data['source_type'],
                    'category_id' => $categoryId,
                    'sort_by' => $data['sort_by'],
                    'product_ids' => [],
                ]
            );
        }
    }
}
