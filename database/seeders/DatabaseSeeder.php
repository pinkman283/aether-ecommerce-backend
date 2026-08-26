<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Users with Distinct Roles
        $superAdmin = User::create([
            'name' => 'Marcus Aurelius (Super Admin)',
            'email' => 'superadmin@ecommerce.test',
            'password' => Hash::make('password123'),
            'role' => 'super_admin',
            'status' => 'active',
            'phone' => '+1 (555) 111-2222',
            'avatar' => 'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?auto=format&fit=crop&w=400&q=80',
        ]);

        $admin = User::create([
            'name' => 'Alexander Vance (Admin)',
            'email' => 'admin@ecommerce.test',
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'status' => 'active',
            'phone' => '+1 (555) 234-5678',
            'avatar' => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=400&q=80',
        ]);

        $staff = User::create([
            'name' => 'Kaitlyn Chen (Inventory Staff)',
            'email' => 'staff@ecommerce.test',
            'password' => Hash::make('password123'),
            'role' => 'staff',
            'status' => 'active',
            'permissions' => ['products.manage', 'orders.manage', 'inventory.manage', 'reviews.manage'],
            'phone' => '+1 (555) 444-5555',
            'avatar' => 'https://images.unsplash.com/photo-1580489944761-15a19d654956?auto=format&fit=crop&w=400&q=80',
        ]);

        $customer = User::create([
            'name' => 'Elena Rostova',
            'email' => 'customer@ecommerce.test',
            'password' => Hash::make('password123'),
            'role' => 'customer',
            'status' => 'active',
            'phone' => '+1 (555) 876-5432',
            'avatar' => 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?auto=format&fit=crop&w=400&q=80',
        ]);

        // 2. Customer Saved Addresses
        Address::create([
            'user_id' => $customer->id,
            'type' => 'shipping',
            'full_name' => 'Elena Rostova',
            'phone' => '+1 (555) 876-5432',
            'address_line1' => '742 Evergreen Terrace, Suite 400',
            'address_line2' => 'Apt 12B',
            'city' => 'San Francisco',
            'state' => 'CA',
            'postal_code' => '94107',
            'country' => 'United States',
            'is_default' => true,
        ]);

        Address::create([
            'user_id' => $customer->id,
            'type' => 'billing',
            'full_name' => 'Elena Rostova',
            'phone' => '+1 (555) 876-5432',
            'address_line1' => '742 Evergreen Terrace, Suite 400',
            'city' => 'San Francisco',
            'state' => 'CA',
            'postal_code' => '94107',
            'country' => 'United States',
            'is_default' => true,
        ]);

        // 3. Coupons
        Coupon::create([
            'code' => 'WELCOME20',
            'type' => 'percentage',
            'value' => 20.00,
            'min_order_amount' => 50.00,
            'max_discount_amount' => 100.00,
            'usage_limit' => 500,
            'is_active' => true,
            'expires_at' => now()->addMonths(6),
        ]);

        Coupon::create([
            'code' => 'CYBER50',
            'type' => 'fixed',
            'value' => 50.00,
            'min_order_amount' => 200.00,
            'usage_limit' => 200,
            'is_active' => true,
            'expires_at' => now()->addMonths(3),
        ]);

        Coupon::create([
            'code' => 'FREESHIP',
            'type' => 'fixed',
            'value' => 15.00,
            'min_order_amount' => 30.00,
            'is_active' => true,
            'expires_at' => now()->addMonths(12),
        ]);

        // 4. Categories
        $categoriesData = [
            [
                'name' => 'Flagship Audio & Acoustics',
                'slug' => 'audio-acoustics',
                'description' => 'Audiophile-grade studio monitors, active noise-cancelling headphones, and wireless planar earbuds.',
                'image' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=800&q=80',
                'icon' => 'Headphones',
                'badge' => 'Hi-Res Lossless',
                'is_featured' => true,
                'display_order' => 1,
            ],
            [
                'name' => 'Mechanical Keyboards & Desks',
                'slug' => 'keyboards-desks',
                'description' => 'Precision CNC aluminum chassis, custom gasket-mounted typing soundscapes, and magnetic switches.',
                'image' => 'https://images.unsplash.com/photo-1587829741301-dc798b83add3?auto=format&fit=crop&w=800&q=80',
                'icon' => 'Keyboard',
                'badge' => 'Custom Modded',
                'is_featured' => true,
                'display_order' => 2,
            ],
            [
                'name' => 'Everyday Carry & Tech Packs',
                'slug' => 'everyday-carry',
                'description' => 'Weatherproof ballistic nylon backpacks, magnetic fidlock slings, and modular cable organizers.',
                'image' => 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=800&q=80',
                'icon' => 'Briefcase',
                'badge' => 'X-Pac Waterproof',
                'is_featured' => true,
                'display_order' => 3,
            ],
            [
                'name' => 'Smart Living & Ambient Lighting',
                'slug' => 'smart-living-lighting',
                'description' => 'Architectural LED bars, circadian rhythm sync light tubes, and smart workstation docks.',
                'image' => 'https://images.unsplash.com/photo-1507473885765-e6ed057f782c?auto=format&fit=crop&w=800&q=80',
                'icon' => 'Sparkles',
                'badge' => 'Smart Sync',
                'is_featured' => true,
                'display_order' => 4,
            ],
            [
                'name' => 'Pro Cyber Wearables',
                'slug' => 'pro-wearables',
                'description' => 'Titanium smartwatch chronographs, biometrics rings, and high-performance neural monitors.',
                'image' => 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?auto=format&fit=crop&w=800&q=80',
                'icon' => 'Watch',
                'badge' => 'Titanium Grade 5',
                'is_featured' => false,
                'display_order' => 5,
            ],
        ];

        $createdCategories = [];
        foreach ($categoriesData as $c) {
            $createdCategories[$c['slug']] = Category::create($c);
        }

        // 5. Products Catalog
        $products = [
            [
                'category_slug' => 'audio-acoustics',
                'name' => 'Aether Pulse ANC Wireless Studio Headphones',
                'slug' => 'aether-pulse-anc-wireless-headphones',
                'brand' => 'Aether Audio',
                'sku' => 'AETH-PLS-01',
                'short_description' => 'Custom 50mm Beryllium drivers, 45dB hybrid active noise cancellation, and 60-hour lossless battery life.',
                'description' => "Engineered for music producers, audio purists, and world travelers. The Aether Pulse ANC combines precision aircraft-grade aluminum hinges with ultra-plush memory foam lambskin earcups. Features dual Bluetooth 5.4 multipoint connectivity and ultra-low latency 2.4GHz wireless dongle.",
                'price' => 349.00,
                'compare_at_price' => 429.00,
                'stock_quantity' => 42,
                'is_featured' => true,
                'is_new_arrival' => true,
                'is_best_seller' => true,
                'rating_average' => 4.92,
                'review_count' => 128,
                'tags' => ['ANC', 'Wireless', 'Audiophile', 'Lossless', 'Studio'],
                'specifications' => [
                    'Driver Unit' => '50mm Beryllium Diaphragm',
                    'Frequency Response' => '5Hz - 48,000Hz',
                    'Battery Life' => '60 Hours (ANC On)',
                    'Bluetooth Codecs' => 'LDAC, aptX Adaptive, AAC, SBC',
                    'Weight' => '278 grams',
                ],
                'images' => [
                    'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=1200&q=85',
                    'https://images.unsplash.com/photo-1583394838336-acd977736f90?auto=format&fit=crop&w=1200&q=85',
                    'https://images.unsplash.com/photo-1546435770-a3e426bf472b?auto=format&fit=crop&w=1200&q=85',
                ],
                'variants' => [
                    ['name' => 'Obsidian Black', 'color_name' => 'Obsidian Black', 'color_hex' => '#111827', 'price_modifier' => 0.00, 'stock_quantity' => 20],
                    ['name' => 'Lunar Silver', 'color_name' => 'Lunar Silver', 'color_hex' => '#E5E7EB', 'price_modifier' => 0.00, 'stock_quantity' => 14],
                    ['name' => 'Midnight Blue', 'color_name' => 'Midnight Blue', 'color_hex' => '#1E3A8A', 'price_modifier' => 20.00, 'stock_quantity' => 8],
                ],
            ],
            [
                'category_slug' => 'audio-acoustics',
                'name' => 'Onyx Pro Planar Magnetic IEM Earphones',
                'slug' => 'onyx-pro-planar-magnetic-earphones',
                'brand' => 'Sonix Labs',
                'sku' => 'SNX-ONX-99',
                'short_description' => '14.2mm ultra-thin planar magnetic driver with 8-core silver-plated monocrystalline copper cable.',
                'description' => "Designed for uncompromising sound separation and zero-distortion treble extension. The Onyx Pro housing is 3D printed with hypoallergenic bio-resin and capped with real Damascus steel faceplates.",
                'price' => 189.00,
                'compare_at_price' => 220.00,
                'stock_quantity' => 65,
                'is_featured' => false,
                'is_new_arrival' => true,
                'is_best_seller' => false,
                'rating_average' => 4.85,
                'review_count' => 64,
                'tags' => ['Planar', 'IEM', 'Hi-Fi', 'Audiophile'],
                'specifications' => [
                    'Driver Type' => '14.2mm Planar Magnetic',
                    'Impedance' => '16 Ohms',
                    'Sensitivity' => '102 dB/mW',
                    'Connector' => '0.78mm 2-Pin Detachable',
                ],
                'images' => [
                    'https://images.unsplash.com/photo-1590658268037-6bf12165a8df?auto=format&fit=crop&w=1200&q=85',
                    'https://images.unsplash.com/photo-1606220588913-b3aacb4d2f46?auto=format&fit=crop&w=1200&q=85',
                ],
                'variants' => [
                    ['name' => 'Damascus Silver', 'color_name' => 'Damascus Silver', 'color_hex' => '#94A3B8', 'price_modifier' => 0.00, 'stock_quantity' => 35],
                    ['name' => 'Smoky Amber', 'color_name' => 'Smoky Amber', 'color_hex' => '#D97706', 'price_modifier' => 10.00, 'stock_quantity' => 30],
                ],
            ],
            [
                'category_slug' => 'keyboards-desks',
                'name' => 'Vortex 75 CNC Gasket Mechanical Keyboard',
                'slug' => 'vortex-75-cnc-gasket-mechanical-keyboard',
                'brand' => 'Vortex Custom',
                'sku' => 'VRX-75-CNC',
                'short_description' => 'Anodized 6063 Aluminum CNC case, triple-mode wireless, hot-swappable tactile pre-lubed switches.',
                'description' => "Experience the ultimate satisfying, creamy acoustic typing profile. Built with a 5-layer internal dampening acoustic pack (Poron, IXPE, PET, silicone), FR4 flex-cut plate, and programmable RGB rotary knob.",
                'price' => 219.00,
                'compare_at_price' => 269.00,
                'stock_quantity' => 28,
                'is_featured' => true,
                'is_new_arrival' => false,
                'is_best_seller' => true,
                'rating_average' => 4.95,
                'review_count' => 210,
                'tags' => ['Mechanical', 'Wireless', 'Gasket Mount', 'CNC Aluminum', 'RGB'],
                'specifications' => [
                    'Layout' => '75% Compact (82 Keys + Knob)',
                    'Mounting Style' => 'Gasket Mount with Poron strips',
                    'Connectivity' => 'Bluetooth 5.3 / 2.4GHz / Type-C Wired',
                    'Keycaps' => 'Double-shot PBT Cherry Profile',
                    'Battery' => '4000 mAh Li-ion (Up to 200 hours)',
                ],
                'images' => [
                    'https://images.unsplash.com/photo-1587829741301-dc798b83add3?auto=format&fit=crop&w=1200&q=85',
                    'https://images.unsplash.com/photo-1618384887929-16ec33fab9ef?auto=format&fit=crop&w=1200&q=85',
                    'https://images.unsplash.com/photo-1595225476474-87563907a212?auto=format&fit=crop&w=1200&q=85',
                ],
                'variants' => [
                    ['name' => 'Linear Cream Switches / Space Grey', 'size' => 'Linear Cream', 'color_name' => 'Space Grey', 'color_hex' => '#4B5563', 'price_modifier' => 0.00, 'stock_quantity' => 12],
                    ['name' => 'Tactile Holy Panda Switches / Cyber Cyan', 'size' => 'Tactile Panda', 'color_name' => 'Cyber Cyan', 'color_hex' => '#06B6D4', 'price_modifier' => 15.00, 'stock_quantity' => 10],
                    ['name' => 'Silent Sea Salt Switches / E-White', 'size' => 'Silent Salt', 'color_name' => 'Pure White', 'color_hex' => '#F8FAFC', 'price_modifier' => 10.00, 'stock_quantity' => 6],
                ],
            ],
            [
                'category_slug' => 'everyday-carry',
                'name' => 'AeroPack X-Pac 24L Modular Travel Pack',
                'slug' => 'aeropack-xpac-24l-modular-travel-pack',
                'brand' => 'Nomad Vector',
                'sku' => 'NMD-AERO-24',
                'short_description' => 'Dimension-Polyant VX21 waterproof laminate, YKK Aquaguard zippers, and magnetic Fidlock buckles.',
                'description' => "Engineered for technical commuters and digital nomads. The AeroPack 24L features a lay-flat clamshell opening, suspended 16-inch fleece-lined laptop sleeve, quick-access passport pocket, and expandable water bottle holders.",
                'price' => 235.00,
                'compare_at_price' => 280.00,
                'stock_quantity' => 34,
                'is_featured' => true,
                'is_new_arrival' => true,
                'is_best_seller' => true,
                'rating_average' => 4.88,
                'review_count' => 95,
                'tags' => ['Waterproof', 'EDC', 'X-Pac', 'Backpack', 'Travel'],
                'specifications' => [
                    'Capacity' => '24 Liters',
                    'Material' => 'VX21 X-Pac Sailcloth + Cordura 500D',
                    'Laptop Compatibility' => 'Up to 16" MacBook Pro',
                    'Dimensions' => '48cm x 31cm x 18cm',
                    'Weight' => '1.15 kg',
                ],
                'images' => [
                    'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=1200&q=85',
                    'https://images.unsplash.com/photo-1622560480605-d83c853bc5c3?auto=format&fit=crop&w=1200&q=85',
                ],
                'variants' => [
                    ['name' => 'Stealth Black', 'color_name' => 'Stealth Black', 'color_hex' => '#0F172A', 'price_modifier' => 0.00, 'stock_quantity' => 20],
                    ['name' => 'Alpine Olive', 'color_name' => 'Alpine Olive', 'color_hex' => '#3F4A3C', 'price_modifier' => 0.00, 'stock_quantity' => 14],
                ],
            ],
            [
                'category_slug' => 'smart-living-lighting',
                'name' => 'Lumina Horizon Dynamic Desk Lightbar',
                'slug' => 'lumina-horizon-dynamic-desk-lightbar',
                'brand' => 'Lumina Tech',
                'sku' => 'LUM-HRZ-PRO',
                'short_description' => 'Zero-glare optical asymmetrical light guide, ambient RGB back-glow, and auto-dimming ambient sensor.',
                'description' => "Transform your creative workspace. Horizon Lightbar mounts cleanly on curved and flat monitors without blocking webcams. Features 2700K to 6500K dynamic color temperature, Ra97 high color rendering index, and wireless wireless desktop rotary dial.",
                'price' => 129.00,
                'compare_at_price' => 159.00,
                'stock_quantity' => 50,
                'is_featured' => true,
                'is_new_arrival' => false,
                'is_best_seller' => false,
                'rating_average' => 4.79,
                'review_count' => 84,
                'tags' => ['Lighting', 'Desk Setup', 'Ergonomic', 'Smart Home', 'RGB'],
                'specifications' => [
                    'Color Temperature' => '2700K - 6500K Stepless',
                    'Color Rendering' => 'Ra >= 97 CRI',
                    'Power Input' => 'USB-C 5V / 2A',
                    'Control Method' => '2.4G Wireless Remote Dial + App',
                ],
                'images' => [
                    'https://images.unsplash.com/photo-1507473885765-e6ed057f782c?auto=format&fit=crop&w=1200&q=85',
                    'https://images.unsplash.com/photo-1517336714731-489689fd1ca8?auto=format&fit=crop&w=1200&q=85',
                ],
                'variants' => [
                    ['name' => 'Matte Space Grey', 'color_name' => 'Space Grey', 'color_hex' => '#374151', 'price_modifier' => 0.00, 'stock_quantity' => 30],
                    ['name' => 'Ceramic Frost White', 'color_name' => 'Frost White', 'color_hex' => '#F1F5F9', 'price_modifier' => 10.00, 'stock_quantity' => 20],
                ],
            ],
            [
                'category_slug' => 'pro-wearables',
                'name' => 'Chronos Titan Ultra Smartwatch',
                'slug' => 'chronos-titan-ultra-smartwatch',
                'brand' => 'Chronos Labs',
                'sku' => 'CHR-TTN-ULT',
                'short_description' => 'Aerospace Grade 5 Titanium casing, Sapphire crystal display, 100m water resistance, and 14-day battery life.',
                'description' => "Built for extreme endurance and precision biometrics. Includes dual-frequency multi-band GPS, continuous ECG monitoring, VO2 max tracking, and offline topography maps.",
                'price' => 599.00,
                'compare_at_price' => 699.00,
                'stock_quantity' => 18,
                'is_featured' => true,
                'is_new_arrival' => true,
                'is_best_seller' => true,
                'rating_average' => 4.96,
                'review_count' => 140,
                'tags' => ['Titanium', 'GPS', 'Smartwatch', 'ECG', 'Sapphire'],
                'specifications' => [
                    'Case Material' => 'Aerospace Grade 5 Titanium',
                    'Lens' => 'Sapphire Crystal Glass',
                    'Water Rating' => '10 ATM (100 meters)',
                    'Display' => '1.43" AMOLED 466x466 (1000 nits)',
                    'Battery Life' => '14 Days in Smartwatch Mode',
                ],
                'images' => [
                    'https://images.unsplash.com/photo-1523275335684-37898b6baf30?auto=format&fit=crop&w=1200&q=85',
                    'https://images.unsplash.com/photo-1508685096489-7aacd43bd3b1?auto=format&fit=crop&w=1200&q=85',
                ],
                'variants' => [
                    ['name' => 'Titanium Raw / Orange Trail Loop', 'size' => '49mm', 'color_name' => 'Titanium Orange', 'color_hex' => '#EA580C', 'price_modifier' => 0.00, 'stock_quantity' => 10],
                    ['name' => 'DLC Shadow Black / Ocean Fluororubber', 'size' => '49mm', 'color_name' => 'Shadow Black', 'color_hex' => '#18181B', 'price_modifier' => 30.00, 'stock_quantity' => 8],
                ],
            ],
            [
                'category_slug' => 'everyday-carry',
                'name' => 'Orbit Magnetic Tech Organiser Pouch',
                'slug' => 'orbit-magnetic-tech-organiser-pouch',
                'brand' => 'Nomad Vector',
                'sku' => 'NMD-ORB-PCH',
                'short_description' => 'Origami-style accordion organizer with dedicated power bank pass-through and magnetic closure.',
                'description' => "Never untangle chargers again. Features 18 distinct compartments for cables, SD cards, GaN chargers, Apple Pencils, and external SSDs.",
                'price' => 58.00,
                'compare_at_price' => 70.00,
                'stock_quantity' => 80,
                'is_featured' => false,
                'is_new_arrival' => false,
                'is_best_seller' => true,
                'rating_average' => 4.82,
                'review_count' => 112,
                'tags' => ['EDC', 'Organizer', 'Tech Pouch', 'Travel'],
                'specifications' => [
                    'Capacity' => '2.5 Liters',
                    'Material' => 'Recycled 420D Nylon Cordura',
                    'Zippers' => 'Weatherproof YKK RC5',
                    'Weight' => '240 grams',
                ],
                'images' => [
                    'https://images.unsplash.com/photo-1544816155-12df9643f363?auto=format&fit=crop&w=1200&q=85',
                ],
                'variants' => [
                    ['name' => 'Charcoal Heather', 'color_name' => 'Charcoal', 'color_hex' => '#334155', 'price_modifier' => 0.00, 'stock_quantity' => 45],
                    ['name' => 'Desert Sand', 'color_name' => 'Sand', 'color_hex' => '#D4B996', 'price_modifier' => 0.00, 'stock_quantity' => 35],
                ],
            ],
            [
                'category_slug' => 'smart-living-lighting',
                'name' => 'HyperCharge 140W GaN Desktop Power Station',
                'slug' => 'hypercharge-140w-gan-desktop-power-station',
                'brand' => 'Lumina Tech',
                'sku' => 'LUM-HYP-140',
                'short_description' => 'Gallium Nitride fast charger with real-time OLED power wattage display and 4 USB ports.',
                'description' => "Power your entire workstation from a single compact brick. Delivers full 140W PD 3.1 to fast-charge 16-inch laptops in 30 minutes alongside smartphones and accessories simultaneously.",
                'price' => 99.00,
                'compare_at_price' => 120.00,
                'stock_quantity' => 60,
                'is_featured' => false,
                'is_new_arrival' => true,
                'is_best_seller' => false,
                'rating_average' => 4.90,
                'review_count' => 48,
                'tags' => ['GaN', 'Fast Charge', '140W', 'OLED Display', 'USB-C'],
                'specifications' => [
                    'Max Output' => '140W PD 3.1',
                    'Ports' => '3x USB-C + 1x USB-A',
                    'Display' => 'Real-Time OLED Wattage & Volts',
                    'Safety' => 'Overvoltage, Thermal & Short Protection',
                ],
                'images' => [
                    'https://images.unsplash.com/photo-1583863788434-e58a36330cf0?auto=format&fit=crop&w=1200&q=85',
                ],
                'variants' => [
                    ['name' => 'Cyber Matte Grey', 'color_name' => 'Matte Grey', 'color_hex' => '#1F2937', 'price_modifier' => 0.00, 'stock_quantity' => 60],
                ],
            ],
        ];

        foreach ($products as $pData) {
            $cat = $createdCategories[$pData['category_slug']] ?? null;
            $product = Product::create([
                'category_id' => $cat?->id,
                'name' => $pData['name'],
                'slug' => $pData['slug'],
                'brand' => $pData['brand'],
                'sku' => $pData['sku'],
                'short_description' => $pData['short_description'],
                'description' => $pData['description'],
                'price' => $pData['price'],
                'compare_at_price' => $pData['compare_at_price'],
                'stock_quantity' => $pData['stock_quantity'],
                'is_featured' => $pData['is_featured'],
                'is_new_arrival' => $pData['is_new_arrival'],
                'is_best_seller' => $pData['is_best_seller'],
                'is_active' => true,
                'rating_average' => $pData['rating_average'],
                'review_count' => $pData['review_count'],
                'tags' => $pData['tags'],
                'specifications' => $pData['specifications'],
            ]);

            // Create Images
            foreach ($pData['images'] as $idx => $imgUrl) {
                ProductImage::create([
                    'product_id' => $product->id,
                    'image_url' => $imgUrl,
                    'alt_text' => $product->name,
                    'is_primary' => $idx === 0,
                    'display_order' => $idx,
                ]);
            }

            // Create Variants
            foreach ($pData['variants'] as $v) {
                ProductVariant::create([
                    'product_id' => $product->id,
                    'name' => $v['name'],
                    'size' => $v['size'] ?? null,
                    'color_name' => $v['color_name'] ?? null,
                    'color_hex' => $v['color_hex'] ?? null,
                    'sku' => $product->sku . '-' . Str::upper(Str::random(3)),
                    'price_modifier' => $v['price_modifier'] ?? 0.00,
                    'stock_quantity' => $v['stock_quantity'] ?? 10,
                ]);
            }

            // Create Sample Reviews for each product
            Review::create([
                'product_id' => $product->id,
                'user_id' => $customer->id,
                'user_name' => 'Elena Rostova',
                'user_avatar' => 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?auto=format&fit=crop&w=150&q=80',
                'rating' => 5,
                'title' => 'Exceeded every single expectation!',
                'comment' => "The build quality and tactile finish are out of this world. Delivery was fast and the unboxing packaging felt like opening high-end luxury hardware. Will definitely be purchasing again!",
                'is_verified_purchase' => true,
                'is_approved' => true,
            ]);

            Review::create([
                'product_id' => $product->id,
                'user_id' => null,
                'user_name' => 'Marcus Chen',
                'user_avatar' => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?auto=format&fit=crop&w=150&q=80',
                'rating' => 5,
                'title' => 'Top tier performance in its category',
                'comment' => "I have tried 4 different alternatives in the market and none match the acoustic dampening, software polish, and industrial design of this. 10/10 recommendation.",
                'is_verified_purchase' => true,
                'is_approved' => true,
            ]);
        }

        // 6. Create Demo Completed Order for Customer
        $firstProduct = Product::where('slug', 'aether-pulse-anc-wireless-headphones')->first();
        $firstVariant = $firstProduct->variants->first();

        $secondProduct = Product::where('slug', 'orbit-magnetic-tech-organiser-pouch')->first();
        $secondVariant = $secondProduct->variants->first();

        $subtotal = $firstProduct->price + $secondProduct->price;
        $discount = 20.00;
        $shipping = 0.00; // Free shipping over $100
        $tax = round(($subtotal - $discount) * 0.08, 2);
        $total = ($subtotal - $discount) + $shipping + $tax;

        $order = Order::create([
            'user_id' => $customer->id,
            'order_number' => 'ORD-2026-98421',
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => $customer->phone,
            'shipping_address' => [
                'full_name' => 'Elena Rostova',
                'address_line1' => '742 Evergreen Terrace, Suite 400',
                'city' => 'San Francisco',
                'state' => 'CA',
                'postal_code' => '94107',
                'country' => 'United States',
                'phone' => '+1 (555) 876-5432',
            ],
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'shipping_amount' => $shipping,
            'discount_amount' => $discount,
            'total_amount' => $total,
            'payment_status' => 'paid',
            'payment_method' => 'credit_card',
            'payment_transaction_id' => 'tx_mock_98243178652',
            'order_status' => 'shipped',
            'tracking_code' => 'FEDEX-99824156291',
            'carrier' => 'FedEx Express (2-Day)',
            'coupon_code' => 'WELCOME20',
            'shipped_at' => now()->subDay(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $firstProduct->id,
            'variant_id' => $firstVariant?->id,
            'product_name' => $firstProduct->name,
            'product_sku' => $firstProduct->sku,
            'product_image' => $firstProduct->images->first()?->image_url,
            'variant_name' => $firstVariant?->name,
            'unit_price' => $firstProduct->price,
            'quantity' => 1,
            'total_price' => $firstProduct->price,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $secondProduct->id,
            'variant_id' => $secondVariant?->id,
            'product_name' => $secondProduct->name,
            'product_sku' => $secondProduct->sku,
            'product_image' => $secondProduct->images->first()?->image_url,
            'variant_name' => $secondVariant?->name,
            'unit_price' => $secondProduct->price,
            'quantity' => 1,
            'total_price' => $secondProduct->price,
        ]);

        // 7. System Operational Settings
        \App\Models\Setting::set('store_name', 'AETHER Hardware Labs', 'general', 'string', 'Store Name');
        \App\Models\Setting::set('support_email', 'ops@aether-audio.test', 'general', 'string', 'Support Email');
        \App\Models\Setting::set('currency', 'USD', 'general', 'string', 'Default Currency');
        \App\Models\Setting::set('tax_rate', '8.0', 'tax', 'number', 'Sales Tax Rate (%)');
        \App\Models\Setting::set('free_shipping_threshold', '100.0', 'shipping', 'number', 'Free Shipping Threshold ($)');
        \App\Models\Setting::set('standard_shipping_rate', '15.0', 'shipping', 'number', 'Standard Shipping Fee ($)');
        \App\Models\Setting::set('priority_shipping_rate', '25.0', 'shipping', 'number', 'Overnight Priority Shipping Fee ($)');
        \App\Models\Setting::set('order_auto_fulfillment', false, 'orders', 'boolean', 'Auto Fulfill Digital Gear');

        // 8. Initial Audit Logs
        \App\Models\AuditLog::log(
            $superAdmin,
            'system.initialized',
            'System',
            1,
            'Database schemas and initial enterprise catalog initialized.',
            null,
            ['status' => 'operational', 'environment' => 'production_ready']
        );
        \App\Models\AuditLog::log(
            $admin,
            'catalog.seeded',
            'Product',
            $firstProduct->id,
            "Flagship acoustic product {$firstProduct->name} created.",
            null,
            ['price' => $firstProduct->price, 'stock' => $firstProduct->stock_quantity]
        );
    }
}
