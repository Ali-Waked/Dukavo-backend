<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

/**
 * The demo grocery catalogue: category tree plus every product the merchants
 * stock.
 *
 * This is data, not logic — seeders read it and persist it. Keeping it in one
 * place means a product's category, SKU, price band and image always travel
 * together, so a product can never end up in the wrong department or with a
 * price that does not match its variants.
 *
 * `tier` decides which merchants carry a product: 1 is core stock every store
 * has, 2 is the extended range, 3 only the flagship assortment. That is what
 * makes the four demo tenants look like businesses of different sizes instead
 * of copies of each other.
 */
final class DemoCatalog
{
    /**
     * Root categories and their children, in navigation order.
     *
     * @return list<array{slug: string, name: string, description: string, children: list<array{slug: string, name: string, description: string}>}>
     */
    public static function categories(): array
    {
        return [
            [
                'slug' => 'fresh-produce',
                'name' => 'Fresh Produce',
                'description' => 'Fruit and vegetables delivered fresh from local growers.',
                'children' => [
                    ['slug' => 'fruits', 'name' => 'Fruits', 'description' => 'Seasonal and everyday fruit.'],
                    ['slug' => 'vegetables', 'name' => 'Vegetables', 'description' => 'Salad, cooking and root vegetables.'],
                ],
            ],
            [
                'slug' => 'dairy-eggs',
                'name' => 'Dairy & Eggs',
                'description' => 'Milk, cheese, yoghurt and free-range eggs.',
                'children' => [],
            ],
            [
                'slug' => 'bakery',
                'name' => 'Bakery',
                'description' => 'Baked fresh every morning.',
                'children' => [],
            ],
            [
                'slug' => 'beverages',
                'name' => 'Beverages',
                'description' => 'Soft drinks, juices, water, coffee and tea.',
                'children' => [
                    ['slug' => 'soft-drinks', 'name' => 'Soft Drinks', 'description' => 'Colas, lemon-lime and flavoured sodas.'],
                    ['slug' => 'juices', 'name' => 'Juices', 'description' => 'Chilled juice and nectar.'],
                    ['slug' => 'water', 'name' => 'Water', 'description' => 'Still and sparkling bottled water.'],
                    ['slug' => 'coffee-tea', 'name' => 'Coffee & Tea', 'description' => 'Ground, instant and loose-leaf.'],
                ],
            ],
            [
                'slug' => 'snacks',
                'name' => 'Snacks',
                'description' => 'Chocolate, crisps and nuts for any time of day.',
                'children' => [
                    ['slug' => 'chocolate', 'name' => 'Chocolate & Sweets', 'description' => 'Bars, blocks and sharing bags.'],
                    ['slug' => 'crisps-nuts', 'name' => 'Crisps & Nuts', 'description' => 'Savoury snacks and roasted nuts.'],
                ],
            ],
            [
                'slug' => 'frozen-foods',
                'name' => 'Frozen Foods',
                'description' => 'Frozen vegetables, fish, pizza and ready-to-cook favourites.',
                'children' => [],
            ],
            [
                'slug' => 'household',
                'name' => 'Household',
                'description' => 'Cleaning, laundry and paper goods for the home.',
                'children' => [],
            ],
            [
                'slug' => 'personal-care',
                'name' => 'Personal Care',
                'description' => 'Hair, body and oral care essentials.',
                'children' => [],
            ],
            [
                'slug' => 'baby-products',
                'name' => 'Baby Products',
                'description' => 'Nappies, wipes and infant nutrition.',
                'children' => [],
            ],
            [
                'slug' => 'pantry',
                'name' => 'Pantry',
                'description' => 'Staples, oils, condiments and tinned goods.',
                'children' => [
                    ['slug' => 'rice-grains', 'name' => 'Rice & Grains', 'description' => 'Rice, pasta and bulgur.'],
                    ['slug' => 'oils-sauces', 'name' => 'Oils & Sauces', 'description' => 'Cooking oils, dressings and condiments.'],
                    ['slug' => 'canned-goods', 'name' => 'Canned Goods', 'description' => 'Tinned vegetables, pulses and paste.'],
                ],
            ],
        ];
    }

    /**
     * Every demo product.
     *
     * `price`/`cost` of the product row are derived by the seeder from its
     * variants: the catalogue shows a "from" price, which is the cheapest
     * variant, exactly as the service would after a merchant saved it.
     *
     * @return list<array{
     *     key: string,
     *     sku: string,
     *     name: string,
     *     category: string,
     *     description: string,
     *     taxable: bool,
     *     tier: int,
     *     image: string,
     *     variants: list<array{name: string, sku: string, price: float, cost: float}>
     * }>
     */
    public static function products(): array
    {
        return [
            // ------------------------------------------------------------------
            // Fresh produce (zero rated: basic food)
            // ------------------------------------------------------------------
            [
                'key' => 'vine-tomatoes',
                'sku' => 'VEG-TOM',
                'name' => 'Vine Tomatoes',
                'category' => 'fresh-produce/vegetables',
                'description' => 'Firm vine-ripened tomatoes, picked daily and packed in 1 kg trays. Ideal for salads, sauces and everyday cooking.',
                'taxable' => false,
                'tier' => 1,
                'image' => 'tomatoes',
                'variants' => [
                    ['name' => '500 g', 'sku' => 'VEG-TOM-500', 'price' => 1.25, 'cost' => 0.70],
                    ['name' => '1 kg', 'sku' => 'VEG-TOM-1K', 'price' => 2.30, 'cost' => 1.35],
                ],
            ],
            [
                'key' => 'seedless-cucumbers',
                'sku' => 'VEG-CUC',
                'name' => 'Seedless Cucumbers',
                'category' => 'fresh-produce/vegetables',
                'description' => 'Crisp seedless cucumbers, individually wrapped to stay fresh. A staple for salads and sandwiches.',
                'taxable' => false,
                'tier' => 1,
                'image' => 'cucumbers',
                'variants' => [
                    ['name' => '500 g', 'sku' => 'VEG-CUC-500', 'price' => 1.10, 'cost' => 0.55],
                ],
            ],
            [
                'key' => 'baby-spinach',
                'sku' => 'VEG-SPI',
                'name' => 'Baby Spinach',
                'category' => 'fresh-produce/vegetables',
                'description' => 'Triple-washed baby spinach leaves in a resealable bag. Ready to eat straight from the pack.',
                'taxable' => false,
                'tier' => 2,
                'image' => 'spinach',
                'variants' => [
                    ['name' => '250 g', 'sku' => 'VEG-SPI-250', 'price' => 1.75, 'cost' => 0.95],
                ],
            ],
            [
                'key' => 'navel-oranges',
                'sku' => 'FRT-ORA',
                'name' => 'Navel Oranges',
                'category' => 'fresh-produce/fruits',
                'description' => 'Seedless navel oranges with a thick, easy-peel skin. Sweet eating oranges packed by weight.',
                'taxable' => false,
                'tier' => 1,
                'image' => 'oranges',
                'variants' => [
                    ['name' => '1 kg', 'sku' => 'FRT-ORA-1K', 'price' => 2.10, 'cost' => 1.15],
                ],
            ],
            [
                'key' => 'fresh-strawberries',
                'sku' => 'FRT-STR',
                'name' => 'Fresh Strawberries',
                'category' => 'fresh-produce/fruits',
                'description' => 'Bright red strawberries in a ventilated punnet. Best kept chilled and enjoyed within a few days.',
                'taxable' => false,
                'tier' => 2,
                'image' => 'strawberries',
                'variants' => [
                    ['name' => '400 g', 'sku' => 'FRT-STR-400', 'price' => 3.20, 'cost' => 1.90],
                ],
            ],
            [
                'key' => 'bananas',
                'sku' => 'FRT-BAN',
                'name' => 'Bananas',
                'category' => 'fresh-produce/fruits',
                'description' => 'Fairtrade yellow bananas, sold by the bunch. Ripen naturally on the counter.',
                'taxable' => false,
                'tier' => 1,
                'image' => 'bananas',
                'variants' => [
                    ['name' => '1 kg', 'sku' => 'FRT-BAN-1K', 'price' => 1.60, 'cost' => 0.85],
                ],
            ],
            [
                'key' => 'green-apples',
                'sku' => 'FRT-APL',
                'name' => 'Green Apples',
                'category' => 'fresh-produce/fruits',
                'description' => 'Tart, crunchy green apples for snacking and baking. Carefully sorted for consistent size.',
                'taxable' => false,
                'tier' => 2,
                'image' => 'green-apples',
                'variants' => [
                    ['name' => '1 kg', 'sku' => 'FRT-APL-1K', 'price' => 2.45, 'cost' => 1.40],
                ],
            ],

            // ------------------------------------------------------------------
            // Dairy & eggs (zero rated)
            // ------------------------------------------------------------------
            [
                'key' => 'full-fat-milk',
                'sku' => 'DAI-MLK',
                'name' => 'Full-Fat Fresh Milk',
                'category' => 'dairy-eggs',
                'description' => 'Pasteurised full-fat milk with 3.5% fat, homogenised and fortified with vitamin D.',
                'taxable' => false,
                'tier' => 1,
                'image' => 'milk',
                'variants' => [
                    ['name' => '1 L', 'sku' => 'DAI-MLK-1L', 'price' => 1.45, 'cost' => 0.95],
                    ['name' => '6 × 1 L', 'sku' => 'DAI-MLK-6X1L', 'price' => 7.90, 'cost' => 5.40],
                ],
            ],
            [
                'key' => 'labneh',
                'sku' => 'DAI-LAB',
                'name' => 'Strained Labneh',
                'category' => 'dairy-eggs',
                'description' => 'Thick strained yoghurt cheese with a clean, tangy flavour. Spoonable, with no additives.',
                'taxable' => false,
                'tier' => 2,
                'image' => 'labneh',
                'variants' => [
                    ['name' => '500 g', 'sku' => 'DAI-LAB-500', 'price' => 2.20, 'cost' => 1.35],
                ],
            ],
            [
                'key' => 'greek-yogurt',
                'sku' => 'DAI-YOG',
                'name' => 'Greek Style Yoghurt',
                'category' => 'dairy-eggs',
                'description' => 'Thick, creamy Greek-style yoghurt with live cultures. Plain and unsweetened.',
                'taxable' => false,
                'tier' => 2,
                'image' => 'yogurt',
                'variants' => [
                    ['name' => '170 g', 'sku' => 'DAI-YOG-170', 'price' => 0.85, 'cost' => 0.48],
                    ['name' => '1 kg', 'sku' => 'DAI-YOG-1K', 'price' => 3.95, 'cost' => 2.60],
                ],
            ],
            [
                'key' => 'free-range-eggs',
                'sku' => 'DAI-EGG',
                'name' => 'Free-Range Eggs',
                'category' => 'dairy-eggs',
                'description' => 'Grade A free-range eggs from inspected farms, packed in a moulded tray to prevent breakage.',
                'taxable' => false,
                'tier' => 1,
                'image' => 'eggs',
                'variants' => [
                    ['name' => '15 pcs', 'sku' => 'DAI-EGG-15', 'price' => 2.75, 'cost' => 1.85],
                    ['name' => '30 pcs', 'sku' => 'DAI-EGG-30', 'price' => 5.20, 'cost' => 3.60],
                ],
            ],
            [
                'key' => 'cheddar-slices',
                'sku' => 'DAI-CHD',
                'name' => 'Processed Cheddar Slices',
                'category' => 'dairy-eggs',
                'description' => 'Mild cheddar slices that melt evenly. Separated for quick sandwiches and toasties.',
                'taxable' => false,
                'tier' => 2,
                'image' => 'cheese-slices',
                'variants' => [
                    ['name' => '200 g', 'sku' => 'DAI-CHD-200', 'price' => 2.40, 'cost' => 1.55],
                ],
            ],
            [
                'key' => 'fresh-butter',
                'sku' => 'DAI-BTR',
                'name' => 'Fresh Butter',
                'category' => 'dairy-eggs',
                'description' => '82% fat churned butter, lightly salted. Wrapped in foil to protect the flavour.',
                'taxable' => false,
                'tier' => 2,
                'image' => 'butter',
                'variants' => [
                    ['name' => '250 g', 'sku' => 'DAI-BTR-250', 'price' => 2.65, 'cost' => 1.75],
                ],
            ],

            // ------------------------------------------------------------------
            // Bakery (zero rated)
            // ------------------------------------------------------------------
            [
                'key' => 'whole-wheat-bread',
                'sku' => 'BAK-BRD',
                'name' => 'Whole Wheat Bread',
                'category' => 'bakery',
                'description' => 'Soft whole wheat loaf baked with no palm oil. Sliced for sandwiches and toasted from frozen.',
                'taxable' => false,
                'tier' => 1,
                'image' => 'bread',
                'variants' => [
                    ['name' => '400 g', 'sku' => 'BAK-BRD-400', 'price' => 1.15, 'cost' => 0.62],
                    ['name' => '800 g', 'sku' => 'BAK-BRD-800', 'price' => 1.95, 'cost' => 1.15],
                ],
            ],
            [
                'key' => 'sourdough-loaf',
                'sku' => 'BAK-SDG',
                'name' => 'Sourdough Loaf',
                'category' => 'bakery',
                'description' => 'Slow-fermented sourdough with a crisp crust and open crumb. Baked in-store every morning.',
                'taxable' => false,
                'tier' => 2,
                'image' => 'sourdough',
                'variants' => [
                    ['name' => '500 g', 'sku' => 'BAK-SDG-500', 'price' => 2.80, 'cost' => 1.60],
                ],
            ],
            [
                'key' => 'arabic-pita',
                'sku' => 'BAK-PIT',
                'name' => 'Arabic Pita Bread',
                'category' => 'bakery',
                'description' => 'Pocket pita baked on stone, soft enough to fold without splitting. Packed warm.',
                'taxable' => false,
                'tier' => 1,
                'image' => 'pita',
                'variants' => [
                    ['name' => '6 pcs', 'sku' => 'BAK-PIT-6', 'price' => 1.05, 'cost' => 0.55],
                    ['name' => '12 pcs', 'sku' => 'BAK-PIT-12', 'price' => 1.90, 'cost' => 1.05],
                ],
            ],
            [
                'key' => 'butter-croissants',
                'sku' => 'BAK-CRO',
                'name' => 'Butter Croissants',
                'category' => 'bakery',
                'description' => 'All-butter croissants, laminated for 24 hours and baked to a deep golden finish.',
                'taxable' => false,
                'tier' => 2,
                'image' => 'croissants',
                'variants' => [
                    ['name' => '4 pcs', 'sku' => 'BAK-CRO-4', 'price' => 2.95, 'cost' => 1.70],
                ],
            ],

            // ------------------------------------------------------------------
            // Soft drinks & juices
            // ------------------------------------------------------------------
            [
                'key' => 'cola',
                'sku' => 'BEV-COLA',
                'name' => 'Cola Soft Drink',
                'category' => 'beverages/soft-drinks',
                'description' => 'Classic fizzy cola served ice cold. Available as single cans or a fridge pack.',
                'taxable' => true,
                'tier' => 1,
                'image' => 'cola',
                'variants' => [
                    ['name' => '330 ml', 'sku' => 'BEV-COLA-330', 'price' => 0.60, 'cost' => 0.34],
                    ['name' => '6 × 330 ml', 'sku' => 'BEV-COLA-6X330', 'price' => 3.30, 'cost' => 2.05],
                    ['name' => '1.5 L', 'sku' => 'BEV-COLA-15L', 'price' => 1.45, 'cost' => 0.92],
                ],
            ],
            [
                'key' => 'lemon-lime-soda',
                'sku' => 'BEV-LEL',
                'name' => 'Lemon-Lime Soda',
                'category' => 'beverages/soft-drinks',
                'description' => 'Crisp lemon-lime soda with a clean finish. Best served over ice.',
                'taxable' => true,
                'tier' => 2,
                'image' => 'lemon-lime-soda',
                'variants' => [
                    ['name' => '330 ml', 'sku' => 'BEV-LEL-330', 'price' => 0.60, 'cost' => 0.34],
                    ['name' => '1.5 L', 'sku' => 'BEV-LEL-15L', 'price' => 1.40, 'cost' => 0.90],
                ],
            ],
            [
                'key' => 'orange-juice',
                'sku' => 'BEV-OJ',
                'name' => 'Orange Juice',
                'category' => 'beverages/juices',
                'description' => 'Chilled 100% orange juice from concentrate, no added sugar. Shake before serving.',
                'taxable' => true,
                'tier' => 1,
                'image' => 'orange-juice',
                'variants' => [
                    ['name' => '1 L', 'sku' => 'BEV-OJ-1L', 'price' => 2.15, 'cost' => 1.40],
                ],
            ],
            [
                'key' => 'mango-nectar',
                'sku' => 'BEV-MNG',
                'name' => 'Mango Nectar',
                'category' => 'beverages/juices',
                'description' => 'Thick mango nectar with real fruit pulp. Serve well chilled.',
                'taxable' => true,
                'tier' => 2,
                'image' => 'mango-nectar',
                'variants' => [
                    ['name' => '1 L', 'sku' => 'BEV-MNG-1L', 'price' => 2.35, 'cost' => 1.50],
                ],
            ],
            [
                'key' => 'sparkling-water',
                'sku' => 'BEV-SPK',
                'name' => 'Sparkling Water',
                'category' => 'beverages/water',
                'description' => 'Naturally carbonated spring water with fine, persistent bubbles.',
                'taxable' => true,
                'tier' => 1,
                'image' => 'sparkling-water',
                'variants' => [
                    ['name' => '500 ml', 'sku' => 'BEV-SPK-500', 'price' => 0.55, 'cost' => 0.28],
                    ['name' => '1.5 L', 'sku' => 'BEV-SPK-15L', 'price' => 0.95, 'cost' => 0.52],
                    ['name' => '6 × 1.5 L', 'sku' => 'BEV-SPK-6X15L', 'price' => 5.10, 'cost' => 3.25],
                ],
            ],
            [
                'key' => 'mineral-water',
                'sku' => 'BEV-WTR',
                'name' => 'Still Mineral Water',
                'category' => 'beverages/water',
                'description' => 'Still drinking water filtered through natural rock. Sourced and bottled locally.',
                'taxable' => true,
                'tier' => 1,
                'image' => 'water-bottles',
                'variants' => [
                    ['name' => '1.5 L', 'sku' => 'BEV-WTR-15L', 'price' => 0.75, 'cost' => 0.38],
                    ['name' => '6 × 1.5 L', 'sku' => 'BEV-WTR-6X15L', 'price' => 4.10, 'cost' => 2.55],
                ],
            ],

            // ------------------------------------------------------------------
            // Coffee & tea
            // ------------------------------------------------------------------
            [
                'key' => 'arabica-ground-coffee',
                'sku' => 'COF-ARA',
                'name' => 'Arabica Ground Coffee',
                'category' => 'beverages/coffee-tea',
                'description' => 'Medium roast 100% Arabica, ground for filter. Notes of caramel, hazelnut and cocoa.',
                'taxable' => true,
                'tier' => 1,
                'image' => 'ground-coffee',
                'variants' => [
                    ['name' => '250 g', 'sku' => 'COF-ARA-250', 'price' => 6.40, 'cost' => 4.15],
                    ['name' => '500 g', 'sku' => 'COF-ARA-500', 'price' => 11.75, 'cost' => 7.80],
                    ['name' => '1 kg', 'sku' => 'COF-ARA-1000', 'price' => 22.50, 'cost' => 15.20],
                ],
            ],
            [
                'key' => 'instant-coffee',
                'sku' => 'COF-INS',
                'name' => 'Instant Coffee',
                'category' => 'beverages/coffee-tea',
                'description' => 'Freeze-dried instant coffee with a smooth, rounded cup. Resealable jar.',
                'taxable' => true,
                'tier' => 2,
                'image' => 'instant-coffee',
                'variants' => [
                    ['name' => '200 g', 'sku' => 'COF-INS-200', 'price' => 7.25, 'cost' => 4.85],
                ],
            ],
            [
                'key' => 'organic-green-tea',
                'sku' => 'TEA-GRN',
                'name' => 'Organic Green Tea',
                'category' => 'beverages/coffee-tea',
                'description' => 'Whole-leaf organic green tea, gently steamed. 20 individually wrapped bags.',
                'taxable' => true,
                'tier' => 2,
                'image' => 'green-tea',
                'variants' => [
                    ['name' => '20 bags', 'sku' => 'TEA-GRN-20', 'price' => 2.65, 'cost' => 1.55],
                ],
            ],
            [
                'key' => 'black-tea',
                'sku' => 'TEA-BLK',
                'name' => 'Black Tea',
                'category' => 'beverages/coffee-tea',
                'description' => 'Bold black tea blend for everyday brewing. 100 family-size bags.',
                'taxable' => true,
                'tier' => 1,
                'image' => 'black-tea',
                'variants' => [
                    ['name' => '100 bags', 'sku' => 'TEA-BLK-100', 'price' => 3.85, 'cost' => 2.45],
                ],
            ],

            // ------------------------------------------------------------------
            // Chocolate & sweets
            // ------------------------------------------------------------------
            [
                'key' => 'dark-chocolate',
                'sku' => 'SNK-CHO',
                'name' => 'Dark Chocolate 70%',
                'category' => 'snacks/chocolate',
                'description' => 'Single-origin dark chocolate with 70% cocoa. Snapped into squares and wrapped in foil.',
                'taxable' => true,
                'tier' => 1,
                'image' => 'dark-chocolate',
                'variants' => [
                    ['name' => '100 g', 'sku' => 'SNK-CHO-100', 'price' => 2.35, 'cost' => 1.45],
                    ['name' => '200 g', 'sku' => 'SNK-CHO-200', 'price' => 4.30, 'cost' => 2.75],
                ],
            ],
            [
                'key' => 'milk-chocolate-bar',
                'sku' => 'SNK-MCH',
                'name' => 'Milk Chocolate Bar',
                'category' => 'snacks/chocolate',
                'description' => 'Creamy milk chocolate bar with a smooth melt. A pocket-sized treat.',
                'taxable' => true,
                'tier' => 2,
                'image' => 'milk-chocolate',
                'variants' => [
                    ['name' => '45 g', 'sku' => 'SNK-MCH-45', 'price' => 0.95, 'cost' => 0.55],
                ],
            ],
            [
                'key' => 'hazelnut-spread',
                'sku' => 'SNK-HZS',
                'name' => 'Hazelnut Cocoa Spread',
                'category' => 'snacks/chocolate',
                'description' => 'Hazelnut and cocoa spread with a silky texture. Spoon over pancakes or spread on toast.',
                'taxable' => true,
                'tier' => 3,
                'image' => 'chocolate-spread',
                'variants' => [
                    ['name' => '350 g', 'sku' => 'SNK-HZS-350', 'price' => 4.15, 'cost' => 2.80],
                ],
            ],
            [
                'key' => 'gummy-candy',
                'sku' => 'SNK-GUM',
                'name' => 'Fruit Gummy Candy',
                'category' => 'snacks/chocolate',
                'description' => 'Assorted fruit gummies made with real juice. Resealable sharing bag.',
                'taxable' => true,
                'tier' => 2,
                'image' => 'gummy-candy',
                'variants' => [
                    ['name' => '300 g', 'sku' => 'SNK-GUM-300', 'price' => 2.15, 'cost' => 1.30],
                ],
            ],

            // ------------------------------------------------------------------
            // Crisps & nuts
            // ------------------------------------------------------------------
            [
                'key' => 'salted-crisps',
                'sku' => 'SNK-CHP',
                'name' => 'Salted Potato Crisps',
                'category' => 'snacks/crisps-nuts',
                'description' => 'Thinly sliced potatoes fried in sunflower oil and lightly salted.',
                'taxable' => true,
                'tier' => 1,
                'image' => 'potato-chips',
                'variants' => [
                    ['name' => '150 g', 'sku' => 'SNK-CHP-150', 'price' => 1.85, 'cost' => 1.10],
                    ['name' => '6 × 45 g', 'sku' => 'SNK-CHP-6X45', 'price' => 4.50, 'cost' => 2.95],
                ],
            ],
            [
                'key' => 'tortilla-chips',
                'sku' => 'SNK-TRC',
                'name' => 'Tortilla Chips',
                'category' => 'snacks/crisps-nuts',
                'description' => 'Crunchy corn tortilla chips with a hint of sea salt. Made for dipping.',
                'taxable' => true,
                'tier' => 2,
                'image' => 'tortilla-chips',
                'variants' => [
                    ['name' => '200 g', 'sku' => 'SNK-TRC-200', 'price' => 2.05, 'cost' => 1.25],
                ],
            ],
            [
                'key' => 'roasted-peanuts',
                'sku' => 'SNK-PNT',
                'name' => 'Roasted Salted Peanuts',
                'category' => 'snacks/crisps-nuts',
                'description' => 'Dry-roasted peanuts with sea salt, crunchy and fresh. Resealable tub.',
                'taxable' => true,
                'tier' => 1,
                'image' => 'peanuts',
                'variants' => [
                    ['name' => '400 g', 'sku' => 'SNK-PNT-400', 'price' => 3.25, 'cost' => 2.10],
                ],
            ],

            // ------------------------------------------------------------------
            // Frozen foods
            // ------------------------------------------------------------------
            [
                'key' => 'frozen-fries',
                'sku' => 'FRZ-FRY',
                'name' => 'Frozen French Fries',
                'category' => 'frozen-foods',
                'description' => 'Skin-on potato fries, par-fried and frozen. Crisp in the oven from frozen.',
                'taxable' => true,
                'tier' => 1,
                'image' => 'french-fries',
                'variants' => [
                    ['name' => '1 kg', 'sku' => 'FRZ-FRY-1K', 'price' => 3.10, 'cost' => 2.05],
                ],
            ],
            [
                'key' => 'chicken-nuggets',
                'sku' => 'FRZ-NGG',
                'name' => 'Chicken Nuggets',
                'category' => 'frozen-foods',
                'description' => 'Breaded chicken breast nuggets, 78% meat. Ready in ten minutes.',
                'taxable' => true,
                'tier' => 1,
                'image' => 'chicken-nuggets',
                'variants' => [
                    ['name' => '500 g', 'sku' => 'FRZ-NGG-500', 'price' => 4.65, 'cost' => 3.20],
                ],
            ],
            [
                'key' => 'frozen-peas',
                'sku' => 'FRZ-PEA',
                'name' => 'Frozen Green Peas',
                'category' => 'frozen-foods',
                'description' => 'Garden peas flash-frozen within hours of picking. Sweet and bright.',
                'taxable' => true,
                'tier' => 1,
                'image' => 'green-peas',
                'variants' => [
                    ['name' => '1 kg', 'sku' => 'FRZ-PEA-1K', 'price' => 2.75, 'cost' => 1.75],
                ],
            ],
            [
                'key' => 'margherita-pizza',
                'sku' => 'FRZ-PIZ',
                'name' => 'Margherita Frozen Pizza',
                'category' => 'frozen-foods',
                'description' => 'Stone-baked base with tomato and mozzarella, frozen for the freezer drawer.',
                'taxable' => true,
                'tier' => 2,
                'image' => 'frozen-pizza',
                'variants' => [
                    ['name' => '350 g', 'sku' => 'FRZ-PIZ-350', 'price' => 4.20, 'cost' => 2.85],
                ],
            ],
            [
                'key' => 'fish-fillets',
                'sku' => 'FRZ-FSH',
                'name' => 'Frozen Fish Fillets',
                'category' => 'frozen-foods',
                'description' => 'Skinless white fish fillets, portioned and frozen. Bone checked by hand.',
                'taxable' => true,
                'tier' => 3,
                'image' => 'fish-fillets',
                'variants' => [
                    ['name' => '600 g', 'sku' => 'FRZ-FSH-600', 'price' => 7.90, 'cost' => 5.60],
                ],
            ],

            // ------------------------------------------------------------------
            // Household
            // ------------------------------------------------------------------
            [
                'key' => 'dishwashing-liquid',
                'sku' => 'HHD-DSH',
                'name' => 'Dishwashing Liquid',
                'category' => 'household',
                'description' => 'Grease-cutting dish liquid with a fresh lemon fragrance. Gentle on hands.',
                'taxable' => true,
                'tier' => 1,
                'image' => 'dish-soap',
                'variants' => [
                    ['name' => '750 ml', 'sku' => 'HHD-DSH-750', 'price' => 2.45, 'cost' => 1.55],
                    ['name' => '2 × 750 ml', 'sku' => 'HHD-DSH-2X750', 'price' => 4.55, 'cost' => 3.05],
                ],
            ],
            [
                'key' => 'laundry-detergent',
                'sku' => 'HHD-LND',
                'name' => 'Laundry Detergent',
                'category' => 'household',
                'description' => 'Concentrated liquid detergent for colours and whites. Around 60 washes.',
                'taxable' => true,
                'tier' => 2,
                'image' => 'laundry-detergent',
                'variants' => [
                    ['name' => '3 L', 'sku' => 'HHD-LND-3L', 'price' => 9.75, 'cost' => 6.65],
                ],
            ],
            [
                'key' => 'surface-cleaner',
                'sku' => 'HHD-SRF',
                'name' => 'Multi-Surface Cleaner',
                'category' => 'household',
                'description' => 'Everyday cleaner for worktops, tiles and sealed floors. No rinse required.',
                'taxable' => true,
                'tier' => 2,
                'image' => 'surface-cleaner',
                'variants' => [
                    ['name' => '1 L', 'sku' => 'HHD-SRF-1L', 'price' => 3.05, 'cost' => 1.95],
                ],
            ],
            [
                'key' => 'paper-towels',
                'sku' => 'HHD-TPW',
                'name' => 'Kitchen Paper Towels',
                'category' => 'household',
                'description' => 'Two-ply absorbent towels that hold up when wet. Six half-size rolls.',
                'taxable' => true,
                'tier' => 1,
                'image' => 'paper-towels',
                'variants' => [
                    ['name' => '6 rolls', 'sku' => 'HHD-TPW-6', 'price' => 4.30, 'cost' => 2.85],
                ],
            ],
            [
                'key' => 'toilet-paper',
                'sku' => 'HHD-TPP',
                'name' => 'Toilet Tissue',
                'category' => 'household',
                'description' => 'Soft three-ply toilet tissue, 200 sheets per roll. Bulk pack.',
                'taxable' => true,
                'tier' => 1,
                'image' => 'toilet-paper',
                'variants' => [
                    ['name' => '12 rolls', 'sku' => 'HHD-TPP-12', 'price' => 5.60, 'cost' => 3.75],
                ],
            ],
            [
                'key' => 'garbage-bags',
                'sku' => 'HHD-BAG',
                'name' => 'Drawstring Garbage Bags',
                'category' => 'household',
                'description' => 'Heavy-duty 30 litre liners with a tie closure. Unscented, 50 pieces.',
                'taxable' => true,
                'tier' => 2,
                'image' => 'garbage-bags',
                'variants' => [
                    ['name' => '50 pcs', 'sku' => 'HHD-BAG-50', 'price' => 3.45, 'cost' => 2.15],
                ],
            ],

            // ------------------------------------------------------------------
            // Personal care
            // ------------------------------------------------------------------
            [
                'key' => 'shampoo',
                'sku' => 'PC-SHM',
                'name' => 'Daily Care Shampoo',
                'category' => 'personal-care',
                'description' => 'Mild everyday shampoo for normal hair. Sulphate free, with a light herbal scent.',
                'taxable' => true,
                'tier' => 1,
                'image' => 'shampoo',
                'variants' => [
                    ['name' => '250 ml', 'sku' => 'PC-SHM-250', 'price' => 3.45, 'cost' => 2.20],
                    ['name' => '400 ml', 'sku' => 'PC-SHM-400', 'price' => 4.95, 'cost' => 3.30],
                ],
            ],
            [
                'key' => 'conditioner',
                'sku' => 'PC-CND',
                'name' => 'Everyday Conditioner',
                'category' => 'personal-care',
                'description' => 'Lightweight conditioner that detangles without weighing hair down.',
                'taxable' => true,
                'tier' => 2,
                'image' => 'conditioner',
                'variants' => [
                    ['name' => '400 ml', 'sku' => 'PC-CND-400', 'price' => 4.75, 'cost' => 3.15],
                ],
            ],
            [
                'key' => 'body-wash',
                'sku' => 'PC-BW',
                'name' => 'Moisturising Body Wash',
                'category' => 'personal-care',
                'description' => 'Creamy body wash with glycerin, for skin that feels soft after washing.',
                'taxable' => true,
                'tier' => 2,
                'image' => 'body-wash',
                'variants' => [
                    ['name' => '500 ml', 'sku' => 'PC-BW-500', 'price' => 3.85, 'cost' => 2.55],
                ],
            ],
            [
                'key' => 'toothpaste',
                'sku' => 'PC-TPT',
                'name' => 'Fluoride Toothpaste',
                'category' => 'personal-care',
                'description' => 'Fluoride toothpaste for cavity protection and fresh breath. Family size tube.',
                'taxable' => true,
                'tier' => 1,
                'image' => 'toothpaste',
                'variants' => [
                    ['name' => '100 ml', 'sku' => 'PC-TPT-100', 'price' => 1.95, 'cost' => 1.15],
                ],
            ],
            [
                'key' => 'hand-soap',
                'sku' => 'PC-HSP',
                'name' => 'Liquid Hand Soap',
                'category' => 'personal-care',
                'description' => 'Gentle liquid soap with a pump dispenser. Antibacterial, dermatologically tested.',
                'taxable' => true,
                'tier' => 2,
                'image' => 'hand-soap',
                'variants' => [
                    ['name' => '300 ml', 'sku' => 'PC-HSP-300', 'price' => 2.25, 'cost' => 1.40],
                ],
            ],
            [
                'key' => 'deodorant-spray',
                'sku' => 'PC-DEO',
                'name' => 'Anti-Perspirant Spray',
                'category' => 'personal-care',
                'description' => '48-hour protection anti-perspirant in a fast-drying spray.',
                'taxable' => true,
                'tier' => 3,
                'image' => 'deodorant',
                'variants' => [
                    ['name' => '150 ml', 'sku' => 'PC-DEO-150', 'price' => 3.20, 'cost' => 2.05],
                ],
            ],

            // ------------------------------------------------------------------
            // Baby
            // ------------------------------------------------------------------
            [
                'key' => 'baby-diapers',
                'sku' => 'BBY-DPR',
                'name' => 'Baby Diapers',
                'category' => 'baby-products',
                'description' => 'Ultra-absorbent nappies with a wetness indicator and soft elasticated waist.',
                'taxable' => true,
                'tier' => 1,
                'image' => 'diapers',
                'variants' => [
                    ['name' => 'Size 3 · 44 pcs', 'sku' => 'BBY-DPR-S3', 'price' => 8.95, 'cost' => 6.10],
                    ['name' => 'Size 4 · 40 pcs', 'sku' => 'BBY-DPR-S4', 'price' => 9.45, 'cost' => 6.50],
                    ['name' => 'Size 5 · 32 pcs', 'sku' => 'BBY-DPR-S5', 'price' => 9.95, 'cost' => 6.90],
                ],
            ],
            [
                'key' => 'baby-wipes',
                'sku' => 'BBY-WIP',
                'name' => 'Baby Wipes',
                'category' => 'baby-products',
                'description' => 'Fragrance-free wipes with 99% water, soft enough for newborn skin.',
                'taxable' => true,
                'tier' => 1,
                'image' => 'baby-wipes',
                'variants' => [
                    ['name' => '80 pcs', 'sku' => 'BBY-WIP-80', 'price' => 1.75, 'cost' => 1.05],
                    ['name' => '3 × 80 pcs', 'sku' => 'BBY-WIP-3X80', 'price' => 4.75, 'cost' => 3.10],
                ],
            ],
            [
                'key' => 'infant-formula',
                'sku' => 'BBY-FRM',
                'name' => 'Infant Formula Stage 1',
                'category' => 'baby-products',
                'description' => 'Follow-on starter formula with iron and DHA, for the first six months.',
                'taxable' => true,
                'tier' => 2,
                'image' => 'infant-formula',
                'variants' => [
                    ['name' => '800 g', 'sku' => 'BBY-FRM-800', 'price' => 14.50, 'cost' => 10.80],
                ],
            ],

            // ------------------------------------------------------------------
            // Rice, pasta & grains
            // ------------------------------------------------------------------
            [
                'key' => 'premium-basmati-rice',
                'sku' => 'RIC-BAS',
                'name' => 'Premium Basmati Rice',
                'category' => 'pantry/rice-grains',
                'description' => 'Aged Indian basmati with long, separate grains and a nutty aroma. Wash twice before cooking.',
                'taxable' => false,
                'tier' => 1,
                'image' => 'basmati-rice',
                'variants' => [
                    ['name' => '1 kg', 'sku' => 'RIC-BAS-1K', 'price' => 3.75, 'cost' => 2.45],
                    ['name' => '5 kg', 'sku' => 'RIC-BAS-5K', 'price' => 16.90, 'cost' => 11.85],
                    ['name' => '10 kg', 'sku' => 'RIC-BAS-10K', 'price' => 31.50, 'cost' => 22.60],
                ],
            ],
            [
                'key' => 'spaghetti',
                'sku' => 'PAN-PST',
                'name' => 'Durum Wheat Spaghetti',
                'category' => 'pantry/rice-grains',
                'description' => 'Bronze-cut durum spaghetti that holds its bite. Cooks in nine minutes.',
                'taxable' => false,
                'tier' => 1,
                'image' => 'spaghetti',
                'variants' => [
                    ['name' => '500 g', 'sku' => 'PAN-PST-500', 'price' => 1.15, 'cost' => 0.68],
                    ['name' => '3 × 500 g', 'sku' => 'PAN-PST-3X500', 'price' => 3.15, 'cost' => 2.00],
                ],
            ],
            [
                'key' => 'bulgur-wheat',
                'sku' => 'RIC-BLG',
                'name' => 'Coarse Bulgur Wheat',
                'category' => 'pantry/rice-grains',
                'description' => 'Parboiled coarse bulgur for pilaf and tabbouleh. Stone milled and rinsed.',
                'taxable' => false,
                'tier' => 3,
                'image' => 'bulgur',
                'variants' => [
                    ['name' => '1 kg', 'sku' => 'RIC-BLG-1K', 'price' => 2.40, 'cost' => 1.50],
                ],
            ],

            // ------------------------------------------------------------------
            // Oils, sauces & condiments
            // ------------------------------------------------------------------
            [
                'key' => 'extra-virgin-olive-oil',
                'sku' => 'OIL-OLV',
                'name' => 'Extra Virgin Olive Oil',
                'category' => 'pantry/oils-sauces',
                'description' => 'Cold-pressed extra virgin olive oil from early-harvest olives. Peppery finish, low acidity.',
                'taxable' => false,
                'tier' => 1,
                'image' => 'olive-oil',
                'variants' => [
                    ['name' => '250 ml', 'sku' => 'OIL-OLV-250', 'price' => 4.20, 'cost' => 2.85],
                    ['name' => '750 ml', 'sku' => 'OIL-OLV-750', 'price' => 10.90, 'cost' => 7.60],
                    ['name' => '1 L', 'sku' => 'OIL-OLV-1L', 'price' => 13.75, 'cost' => 9.70],
                ],
            ],
            [
                'key' => 'sunflower-oil',
                'sku' => 'OIL-SUN',
                'name' => 'Sunflower Cooking Oil',
                'category' => 'pantry/oils-sauces',
                'description' => 'Refined sunflower oil, light and neutral for frying and baking.',
                'taxable' => false,
                'tier' => 1,
                'image' => 'sunflower-oil',
                'variants' => [
                    ['name' => '1.8 L', 'sku' => 'OIL-SUN-18L', 'price' => 5.40, 'cost' => 3.85],
                ],
            ],
            [
                'key' => 'tomato-ketchup',
                'sku' => 'PAN-KTC',
                'name' => 'Tomato Ketchup',
                'category' => 'pantry/oils-sauces',
                'description' => 'Smooth tomato ketchup with no high-fructose corn syrup. Squeeze bottle.',
                'taxable' => true,
                'tier' => 1,
                'image' => 'ketchup',
                'variants' => [
                    ['name' => '500 ml', 'sku' => 'PAN-KTC-500', 'price' => 2.15, 'cost' => 1.35],
                ],
            ],
            [
                'key' => 'table-salt',
                'sku' => 'PAN-SLT',
                'name' => 'Iodised Table Salt',
                'category' => 'pantry',
                'description' => 'Fine iodised salt, free-flowing and anti-caking. Staple size.',
                'taxable' => false,
                'tier' => 1,
                'image' => 'salt',
                'variants' => [
                    ['name' => '1 kg', 'sku' => 'PAN-SLT-1K', 'price' => 0.85, 'cost' => 0.42],
                ],
            ],
            [
                'key' => 'granulated-sugar',
                'sku' => 'PAN-SUG',
                'name' => 'Granulated Sugar',
                'category' => 'pantry',
                'description' => 'Fine white granulated sugar for tea, baking and preserving.',
                'taxable' => false,
                'tier' => 1,
                'image' => 'sugar',
                'variants' => [
                    ['name' => '1 kg', 'sku' => 'PAN-SUG-1K', 'price' => 1.45, 'cost' => 0.98],
                    ['name' => '2 kg', 'sku' => 'PAN-SUG-2K', 'price' => 2.75, 'cost' => 1.90],
                ],
            ],

            // ------------------------------------------------------------------
            // Canned goods
            // ------------------------------------------------------------------
            [
                'key' => 'chickpeas',
                'sku' => 'CAN-CHK',
                'name' => 'Chickpeas in Brine',
                'category' => 'pantry/canned-goods',
                'description' => 'Cooked chickpeas in lightly salted brine, ready to add to salads and stews.',
                'taxable' => false,
                'tier' => 1,
                'image' => 'chickpeas',
                'variants' => [
                    ['name' => '400 g', 'sku' => 'CAN-CHK-400', 'price' => 1.05, 'cost' => 0.62],
                ],
            ],
            [
                'key' => 'kidney-beans',
                'sku' => 'CAN-KID',
                'name' => 'Red Kidney Beans',
                'category' => 'pantry/canned-goods',
                'description' => 'Firm red kidney beans in brine, drained and ready to use.',
                'taxable' => false,
                'tier' => 2,
                'image' => 'kidney-beans',
                'variants' => [
                    ['name' => '400 g', 'sku' => 'CAN-KID-400', 'price' => 1.15, 'cost' => 0.68],
                ],
            ],
            [
                'key' => 'tomato-paste',
                'sku' => 'CAN-TMP',
                'name' => 'Tomato Paste',
                'category' => 'pantry/canned-goods',
                'description' => 'Concentrated tomato paste, 100% tomato with no added preservatives.',
                'taxable' => false,
                'tier' => 2,
                'image' => 'tomato-paste',
                'variants' => [
                    ['name' => '400 g', 'sku' => 'CAN-TMP-400', 'price' => 1.35, 'cost' => 0.80],
                ],
            ],
        ];
    }

    /**
     * Product keys carried by a merchant of the given size.
     *
     * @param  int  $tiers  highest tier the merchant stocks (1 = core only, 3 = full range)
     * @param  list<string>  $omit  lines the merchant deliberately does not carry
     * @return list<string>
     */
    public static function productKeys(int $tiers, array $omit = []): array
    {
        return array_values(array_map(
            static fn (array $product): string => $product['key'],
            array_filter(
                self::products(),
                static fn (array $product): bool => $product['tier'] <= $tiers
                    && ! in_array($product['key'], $omit, true),
            ),
        ));
    }

    /**
     * A product definition by key.
     *
     * @return array<string, mixed>|null
     */
    public static function product(string $key): ?array
    {
        foreach (self::products() as $product) {
            if ($product['key'] === $key) {
                return $product;
            }
        }

        return null;
    }

    /**
     * Resolve `parent/child` to the id of the leaf category.
     *
     * @param  array<string, int>  $ids  keyed by category slug
     */
    public static function categoryId(array $ids, string $path): int
    {
        $slug = str_contains($path, '/') ? substr($path, (int) strrpos($path, '/') + 1) : $path;

        if (! array_key_exists($slug, $ids)) {
            throw new \RuntimeException("Demo catalogue references unknown category [{$path}].");
        }

        return $ids[$slug];
    }
}
