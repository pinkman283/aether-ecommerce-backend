<?php

namespace Database\Seeders;

use App\Models\BlogCategory;
use App\Models\BlogComment;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\CmsPage;
use App\Models\Color;
use App\Models\FooterColumn;
use App\Models\FooterLink;
use App\Models\SocialLink;
use App\Models\User;
use Illuminate\Database\Seeder;

class Phase1CmsAndColorsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Curated Color Palette
        $colors = [
            ['name' => 'Obsidian Black', 'hex_code' => '#0F172A', 'status' => 'active'],
            ['name' => 'Pure Snow White', 'hex_code' => '#FFFFFF', 'status' => 'active'],
            ['name' => 'Crimson Ember', 'hex_code' => '#EF4444', 'status' => 'active'],
            ['name' => 'Deep Royal Blue', 'hex_code' => '#1D4ED8', 'status' => 'active'],
            ['name' => 'Emerald Green', 'hex_code' => '#10B981', 'status' => 'active'],
            ['name' => 'Cyber Cyan', 'hex_code' => '#06B6D4', 'status' => 'active'],
            ['name' => 'Sunset Orange', 'hex_code' => '#F97316', 'status' => 'active'],
            ['name' => 'Space Gray', 'hex_code' => '#4B5563', 'status' => 'active'],
            ['name' => 'Rose Gold', 'hex_code' => '#E0A96D', 'status' => 'active'],
            ['name' => 'Lunar Silver', 'hex_code' => '#E5E7EB', 'status' => 'active'],
        ];

        foreach ($colors as $c) {
            Color::firstOrCreate(['name' => $c['name']], $c);
        }

        // 2. Blog Categories
        $categories = [
            [
                'name' => 'Audio Engineering & Acoustics',
                'slug' => 'audio-engineering-acoustics',
                'description' => 'Deep dives into acoustic dampening, transducer tuning, and lossless Bluetooth streaming codecs.',
            ],
            [
                'name' => 'Ergonomics & Workspace Tech',
                'slug' => 'ergonomics-workspace-tech',
                'description' => 'Architecting desk spaces that cultivate flow states, reduce RSI, and elevate creative output.',
            ],
            [
                'name' => 'Product Spotlights',
                'slug' => 'product-spotlights',
                'description' => 'Material breakdown, CNC machining journals, and behind-the-scenes teardowns.',
            ],
            [
                'name' => 'Software & Firmware Updates',
                'slug' => 'software-firmware-updates',
                'description' => 'Changelogs, DSP profile upgrades, and cross-platform companion app releases.',
            ],
        ];

        $createdCats = [];
        foreach ($categories as $cat) {
            $createdCats[$cat['slug']] = BlogCategory::firstOrCreate(['slug' => $cat['slug']], $cat);
        }

        // 3. Blog Tags
        $tagNames = ['Audiophile', 'MechanicalKeyboards', 'Travel', 'SetupInspo', 'HiResLossless', 'Guide'];
        $createdTags = [];
        foreach ($tagNames as $name) {
            $createdTags[] = BlogTag::firstOrCreate(['name' => $name], [
                'name' => $name,
                'slug' => strtolower($name),
            ]);
        }

        // 4. Blog Posts
        $author = User::whereIn('role', ['super_admin', 'admin'])->first() ?? User::first();

        $post1 = BlogPost::firstOrCreate(['slug' => 'inside-acoustic-chamber-engineering-beryllium-driver'], [
            'title' => 'Inside the Acoustic Chamber: Engineering the Aether Pulse 50mm Beryllium Driver',
            'slug' => 'inside-acoustic-chamber-engineering-beryllium-driver',
            'excerpt' => 'How vapor-deposited pure beryllium foil delivers near-zero harmonic distortion and lightning transient response in our flagship studio headphones.',
            'content' => "## The Quest for Transducer Purity\n\nTraditional dynamic headphone drivers rely on cellulose or Mylar diaphragms. While cost-effective, these materials flex unevenly at high frequencies, creating modal breakup and smearing rapid acoustic transients.\n\n### Why Beryllium?\n\nBeryllium possesses an extraordinary stiffness-to-weight ratio — nearly four times stiffer than titanium, yet half the density. In our precision Anechoic chamber tests in Berlin, the 50mm vapor-deposited dome maintained pistonic motion up to 48,000 Hz, well beyond human hearing.\n\n```\nTransient Speed Comparison:\n- Pure Beryllium: 12,890 m/s\n- Titanium Alloy: 5,090 m/s\n- Aluminum: 6,320 m/s\n- Standard Mylar: 1,800 m/s\n```\n\n### Acoustic Chamber Dampening\n\nTo complement the driver speed, we machined a dual-cavity rear resonance chamber lined with high-density Poron micro-foam. The result is a soundstage that feels expansive, three-dimensional, and ruthlessly faithful to the master tape.",
            'featured_image' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=1200&q=85',
            'category_id' => $createdCats['audio-engineering-acoustics']->id ?? null,
            'author_id' => $author?->id,
            'status' => 'published',
            'published_at' => now()->subDays(3),
            'views_count' => 1248,
        ]);

        $post1->tags()->sync([$createdTags[0]->id, $createdTags[4]->id, $createdTags[5]->id]);

        $post2 = BlogPost::firstOrCreate(['slug' => 'how-gasket-mounting-redefined-typing-acoustics'], [
            'title' => 'How Gasket Mounting Redefined Mechanical Typing Acoustics in 2026',
            'slug' => 'how-gasket-mounting-redefined-typing-acoustics',
            'excerpt' => 'From harsh bottom-outs to deep acoustic thocks: the physics behind gasket-mounted keyboard plates and multi-layer Poron isolation.',
            'content' => "## The Evolution of Desk Soundscapes\n\nFor decades, keyboards were built with top mounts or tray mounts where rigid steel screws bound the PCB directly to the metal case. The outcome? Jarring vibrations transferred directly into your fingertips, accompanied by high-pitched clacking.\n\n### The Isolation Gasket Paradigm\n\nGasket mounting isolates the entire keyboard plate and PCB sandwich using custom-molded silicone or Poron foam tabs. The plate floats suspended between the upper and lower aluminum housings, completely free of screw contact.\n\n- **Cushioned Impact**: Gentle leaf-spring rebound prevents finger fatigue during 12-hour programming sprints.\n- **Acoustic Cohesion**: Absorbs pinging frequencies while amplifying switch characteristics.\n\nPairing gasket isolation with double-shot PBT keycaps yields that coveted, deep marbly profile every desk enthusiast craves.",
            'featured_image' => 'https://images.unsplash.com/photo-1587829741301-dc798b83add3?auto=format&fit=crop&w=1200&q=85',
            'category_id' => $createdCats['ergonomics-workspace-tech']->id ?? null,
            'author_id' => $author?->id,
            'status' => 'published',
            'published_at' => now()->subDays(1),
            'views_count' => 842,
        ]);

        $post2->tags()->sync([$createdTags[1]->id, $createdTags[3]->id]);

        // 5. Blog Comments
        BlogComment::firstOrCreate([
            'post_id' => $post1->id,
            'author_email' => 'audiophile.dan@soundforum.org',
        ], [
            'post_id' => $post1->id,
            'author_name' => 'Dan Henderson',
            'author_email' => 'audiophile.dan@soundforum.org',
            'comment' => 'Incredible writeup on the beryllium transient speed. Are there plans for an open-back revision later this year?',
            'is_approved' => true,
        ]);

        BlogComment::firstOrCreate([
            'post_id' => $post2->id,
            'author_email' => 'keebfanatic@mechkeys.io',
        ], [
            'post_id' => $post2->id,
            'author_name' => 'Sophia Lin',
            'author_email' => 'keebfanatic@mechkeys.io',
            'comment' => 'The sound test video on your Instagram sold me immediately. Loving the tactile feel on the Holy Panda variants!',
            'is_approved' => true,
        ]);

        // 6. CMS Pages
        $pages = [
            [
                'title' => 'About Inheliq',
                'slug' => 'about-us',
                'meta_title' => 'About Us - Authentic Vaping Hardware & Flavors',
                'meta_description' => 'Learn about our dedication to 100% authentic vape devices, verified e-liquids, and responsible adult advocacy.',
                'content' => "## Elevate Every Inhale\n\nAt Inheliq, we believe adult vape enthusiasts deserve effortless access to verified, authentic hardware and premium flavors without compromise.\n\nFounded with a dedication to quality and transparency, our catalog features handpicked disposable vapes, pod systems, e-liquids, and accessories from the world's most trusted manufacturers.\n\n### Our Quality Standard\n1. **100% Authentic Products**: All hardware and e-liquids are sourced through authorized channels.\n2. **Adult-Only Advocacy**: Strictly 18+ verification with zero tolerance for underage access.\n3. **Express Dispatch**: Swift fulfillment and dedicated customer support.",
                'is_active' => true,
            ],
            [
                'title' => 'Shipping & Delivery Policy',
                'slug' => 'shipping-delivery-policy',
                'meta_title' => 'Global Express Shipping Policy',
                'meta_description' => 'Fast, insured worldwide courier delivery via FedEx, DHL Express, and local parcel carriers.',
                'content' => "## Global Fulfillment Standards\n\nAll orders placed before 2:00 PM EST are dispatched same-day from our climate-controlled fulfillment centers in Ohio and Frankfurt.\n\n### Shipping Tiers\n- **Standard Ground (3-5 Business Days)**: Free for all orders over $100. $15 flat rate under $100.\n- **FedEx Express 2-Day Priority**: $25 worldwide.\n- **DHL Overnight Courier**: $45 (Next-day by 10:30 AM).\n\nEvery parcel is dispatched in custom shock-absorbent, tamper-evident biodegradable packaging with real-time GPS tracking codes.",
                'is_active' => true,
            ],
            [
                'title' => 'Terms & Conditions of Service',
                'slug' => 'terms-and-conditions',
                'meta_title' => 'Terms of Service | Aether Hardware Labs',
                'meta_description' => 'Legal terms governing your orders, warranty coverage, and software usage.',
                'content' => "## 1. Acceptance of Terms\n\nBy accessing this storefront or placing an order, you agree to be bound by these Terms of Service, applicable laws, and regulations.\n\n## 2. Hardware 2-Year Limited Warranty\n\nAll hardware units carry an unconditional 24-month manufacturer warranty covering defects in materials, acoustic drivers, switches, and chassis construction. Accidental physical drops or unauthorized liquid submersion are not covered.\n\n## 3. Commercial Invoicing & Tax\n\nAll sales include digital PDF tax invoices issued immediately upon payment settlement.",
                'is_active' => true,
            ],
            [
                'title' => 'Privacy & Data Protection Policy',
                'slug' => 'privacy-policy',
                'meta_title' => 'Privacy Policy & GDPR Compliance',
                'meta_description' => 'How we protect your personal information, address history, and checkout security.',
                'content' => "## Your Privacy is Non-Negotiable\n\nWe adhere strictly to GDPR, CCPA, and global privacy frameworks. We do not sell your personal data or browsing behavior to third-party ad brokers.\n\n### Data We Collect\n- **Checkout Essentials**: Name, shipping destination, phone number, and transaction receipt token.\n- **Payment Security**: Card details are processed directly through end-to-end PCI-DSS Level 1 tokenized gateways. We never store raw credit card numbers on our servers.\n\n### Account Erasure\nYou may request a complete export or permanent deletion of your customer record at any time by contacting privacy@aether-audio.test.",
                'is_active' => true,
            ],
            [
                'title' => 'Frequently Asked Questions (FAQ)',
                'slug' => 'faq',
                'meta_title' => 'Help Center & Frequently Asked Questions',
                'meta_description' => 'Answers to common questions regarding orders, compatibility, warranties, and care.',
                'content' => "## Common Questions\n\n### Do the headphones work with PlayStation 5 and Mac?\nYes. The included 2.4GHz low-latency USB-C wireless transmitter is plug-and-play compatible with macOS, Windows 11, PlayStation 5, and iPad Pro.\n\n### What switches come pre-installed in the Vortex 75 keyboard?\nDepending on your selection, you receive factory hand-lubed linear Cream switches (45g actuation) or Holy Panda tactile switches (62g bottom-out).\n\n### Can I return an item if I change my mind?\nWe offer an unconditional 30-day trial period. If you are not completely enchanted by your gear, return it in original packaging for a full refund.",
                'is_active' => true,
            ],
        ];

        foreach ($pages as $p) {
            CmsPage::firstOrCreate(['slug' => $p['slug']], $p);
        }

        // 7. Footer Navigation Columns & Links
        $columns = [
            [
                'title' => 'SHOP',
                'sort_order' => 1,
                'links' => [
                    ['title' => 'All Products', 'url' => '/products', 'sort_order' => 1],
                    ['title' => 'Disposable Vapes', 'url' => '/products?category=disposable-vapes', 'sort_order' => 2],
                    ['title' => 'Pod Systems', 'url' => '/products?category=pod-systems', 'sort_order' => 3],
                    ['title' => 'E-Liquids', 'url' => '/products?category=e-liquids', 'sort_order' => 4],
                    ['title' => 'Vape Accessories', 'url' => '/products?category=vape-accessories', 'sort_order' => 5],
                    ['title' => 'New Arrivals', 'url' => '/products?sort=newest', 'sort_order' => 6],
                    ['title' => 'Best Sellers', 'url' => '/products?sort=best-selling', 'sort_order' => 7],
                ],
            ],
            [
                'title' => 'CUSTOMER CARE',
                'sort_order' => 2,
                'links' => [
                    ['title' => 'Contact Us', 'url' => '/contact', 'sort_order' => 1],
                    ['title' => 'Track Order', 'url' => '/track', 'sort_order' => 2],
                    ['title' => 'Shipping & Delivery', 'url' => '/shipping-policy', 'sort_order' => 3],
                    ['title' => 'Returns & Refunds', 'url' => '/refund-policy', 'sort_order' => 4],
                    ['title' => 'FAQ', 'url' => '/faq', 'sort_order' => 5],
                    ['title' => 'Support', 'url' => '/contact', 'sort_order' => 6],
                ],
            ],
            [
                'title' => 'INFORMATION',
                'sort_order' => 3,
                'links' => [
                    ['title' => 'About Us', 'url' => '/about', 'sort_order' => 1],
                    ['title' => 'Age Verification', 'url' => '/pages/age-verification', 'sort_order' => 2],
                    ['title' => 'Privacy Policy', 'url' => '/privacy', 'sort_order' => 3],
                    ['title' => 'Terms & Conditions', 'url' => '/terms', 'sort_order' => 4],
                    ['title' => 'Cookie Policy', 'url' => '/pages/cookie-policy', 'sort_order' => 5],
                ],
            ],
            [
                'title' => 'QUICK LINKS',
                'sort_order' => 4,
                'links' => [
                    ['title' => 'My Account', 'url' => '/dashboard', 'sort_order' => 1],
                    ['title' => 'Cart', 'url' => '/checkout', 'sort_order' => 2],
                    ['title' => 'Wishlist', 'url' => '/dashboard', 'sort_order' => 3],
                    ['title' => 'Promotions', 'url' => '/promotions', 'sort_order' => 4],
                    ['title' => 'Order History', 'url' => '/dashboard', 'sort_order' => 5],
                ],
            ],
        ];

        foreach ($columns as $c) {
            $col = FooterColumn::firstOrCreate(['title' => $c['title']], [
                'title' => $c['title'],
                'sort_order' => $c['sort_order'],
                'is_active' => true,
            ]);

            foreach ($c['links'] as $fl) {
                FooterLink::firstOrCreate(['title' => $fl['title'], 'footer_column_id' => $col->id], [
                    'footer_column_id' => $col->id,
                    'column_group' => $c['title'],
                    'title' => $fl['title'],
                    'url' => $fl['url'],
                    'is_external' => false,
                    'open_in_new_tab' => false,
                    'sort_order' => $fl['sort_order'],
                    'is_active' => true,
                ]);
            }
        }

        // 8. Social Links
        $socials = [
            ['platform' => 'Instagram', 'url' => 'https://instagram.com/inhaliq', 'icon' => 'instagram', 'sort_order' => 1],
            ['platform' => 'Facebook', 'url' => 'https://facebook.com/inhaliq', 'icon' => 'facebook', 'sort_order' => 2],
            ['platform' => 'YouTube', 'url' => 'https://youtube.com/@inhaliq', 'icon' => 'youtube', 'sort_order' => 3],
            ['platform' => 'X (Twitter)', 'url' => 'https://x.com/inhaliq', 'icon' => 'twitter', 'sort_order' => 4],
            ['platform' => 'Discord', 'url' => 'https://discord.gg/inhaliq', 'icon' => 'discord', 'sort_order' => 5],
        ];

        foreach ($socials as $sl) {
            SocialLink::firstOrCreate(['platform' => $sl['platform']], [
                'platform' => $sl['platform'],
                'url' => $sl['url'],
                'icon' => $sl['icon'],
                'sort_order' => $sl['sort_order'],
                'is_active' => true,
            ]);
        }
    }
}
