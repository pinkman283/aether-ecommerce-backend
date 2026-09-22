<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\NavbarItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NavbarItemsSeeder extends Seeder
{
    public function run(): void
    {
        if (NavbarItem::count() > 0) {
            return;
        }

        $order = 0;

        // 1. Home
        NavbarItem::create([
            'title' => 'Home',
            'type' => 'custom',
            'url' => '/',
            'display_order' => $order++,
            'is_active' => true,
            'mega_menu_type' => 'none',
        ]);

        // 2. Dynamic Root Categories
        $rootCategories = Category::whereNull('parent_id')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        foreach ($rootCategories as $cat) {
            $childrenCount = Category::where('parent_id', $cat->id)->count();
            $megaMenu = $childrenCount > 0 ? 'category_brand_grid' : 'none';

            $parentNav = NavbarItem::create([
                'title' => $cat->name,
                'type' => 'category',
                'category_id' => $cat->id,
                'display_order' => $order++,
                'is_active' => true,
                'mega_menu_type' => $megaMenu,
            ]);

            // Add child subcategories if present
            $subcategories = Category::where('parent_id', $cat->id)->orderBy('display_order')->get();
            $subOrder = 0;
            foreach ($subcategories as $sub) {
                NavbarItem::create([
                    'parent_id' => $parentNav->id,
                    'title' => $sub->name,
                    'type' => 'subcategory',
                    'category_id' => $cat->id,
                    'subcategory_id' => $sub->id,
                    'display_order' => $subOrder++,
                    'is_active' => true,
                    'mega_menu_type' => 'none',
                ]);
            }
        }

        // 3. Fallback standard catalog items if no root categories existed
        if ($rootCategories->isEmpty()) {
            NavbarItem::create([
                'title' => 'Shop All',
                'type' => 'custom',
                'url' => '/products',
                'display_order' => $order++,
                'is_active' => true,
                'mega_menu_type' => 'none',
            ]);
        }

        // 4. Brands dropdown / page
        NavbarItem::create([
            'title' => 'Brands',
            'type' => 'custom',
            'url' => '/brands',
            'display_order' => $order++,
            'is_active' => true,
            'mega_menu_type' => 'none',
        ]);

        // 5. Special Hot Deals item
        NavbarItem::create([
            'title' => 'Special Deals',
            'type' => 'custom',
            'url' => '/products?is_featured=true',
            'badge' => 'SALE',
            'badge_color' => '#EF4444',
            'display_order' => $order++,
            'is_active' => true,
            'mega_menu_type' => 'none',
        ]);
    }
}
