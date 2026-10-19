<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

/**
 * The demo bakery as data: a bakery-cafe in Addis Ababa (product-and-rules.md, "Seed a realistic demo bakery").
 * Code reads it through the typed accessors (stations(), products(), ...): PHPStan types a constant by its literal
 * value, the accessors by their shapes.
 * Phase0Seeder writes it through the module contracts; later phase seeders add to it (shift templates, payment
 * channels, orders, counts) and never rewrite what is here, because earlier gates' e2e projects rely on it.
 *
 * Quantities are decimal strings in the unit named next to them (an item's own unit for stock and batch sizes, the
 * line's unit for recipes); the seeder converts them to base units with Catalog's Units. Prices are ETB, current from
 * the moment the item is first seeded (late 2026 prices of a mid-range cafe). SKUs are the idempotency keys of items.
 *
 * Opening stock: ingredients and bought-in products arrive at the store as purchase_in and are transferred to the
 * locations that work with them; products the bakery and the cake room make are counted where they were made
 * (count_adjustment: an opening count, since nothing was purchased or produced through the system yet) and moved to
 * where they are sold or used.
 *
 * @phpstan-type StockSpec array{qty: string, to?: array<string, string>}
 * @phpstan-type IngredientSpec array{category: string, en: string, am: string, unit: string, stock: StockSpec}
 * @phpstan-type LineSpec array{0: string, 1: string, 2: string, optional?: bool, waste?: string}
 * @phpstan-type RecipeSpec array{yield: array{0: string, 1: string}, lines: list<LineSpec>, steps?: list<array{0: string, 1: string}>}
 * @phpstan-type ProductSpec array{category: string, en: string, am: string, unit: string, fulfilment: string, price: string, route?: string, produced_at?: string, batch?: string, recipe?: RecipeSpec, stock?: StockSpec, new?: bool}
 * @phpstan-type PersonSpec array{role: string, en: string, am: string, phone: string, locale: string, stations: list<array{0: string, 1: bool, 2: bool}>}
 */
final class DemoBakery
{
    public const string DEFAULT_BRANCH = 'Demo Bakery';

    /** The demo admin: the first account created and the actor of every other seeded write. */
    public const string ADMIN_PHONE = '+251900000101';

    /** @var array<string, array{requires_overseer: bool, sort: int}> station code => settings (names: StationCode::defaultName) */
    public const array STATIONS = [
        'till' => ['requires_overseer' => false, 'sort' => 1],
        'kitchen' => ['requires_overseer' => false, 'sort' => 2],
        'bar' => ['requires_overseer' => false, 'sort' => 3],
        'cake_room' => ['requires_overseer' => true, 'sort' => 4],
        'bakery' => ['requires_overseer' => true, 'sort' => 5],
        'store' => ['requires_overseer' => false, 'sort' => 6],
    ];

    /** @var array<string, array{en: string, am: string, station: string}> location type => names and the station working from it */
    public const array LOCATIONS = [
        'store' => ['en' => 'Main store', 'am' => 'ዋና መጋዘን', 'station' => 'store'],
        'kitchen' => ['en' => 'Kitchen', 'am' => 'ኩሽና', 'station' => 'kitchen'],
        'bar' => ['en' => 'Bar', 'am' => 'ባር', 'station' => 'bar'],
        'cake_room' => ['en' => 'Cake room', 'am' => 'የኬክ ክፍል', 'station' => 'cake_room'],
        'bakery' => ['en' => 'Bakery', 'am' => 'ዳቦ ቤት', 'station' => 'bakery'],
        'display_case' => ['en' => 'Display case', 'am' => 'የማሳያ መደርደሪያ', 'station' => 'till'],
        'cash_drawer' => ['en' => 'Cash drawer', 'am' => 'የገንዘብ መሳቢያ', 'station' => 'till'],
    ];

    /** @var array<string, array{kind: string, en: string, am: string, sort: int}> */
    public const array CATEGORIES = [
        'flour' => ['kind' => 'ingredient', 'en' => 'Flour & grains', 'am' => 'ዱቄትና እህል', 'sort' => 1],
        'dairy' => ['kind' => 'ingredient', 'en' => 'Dairy & eggs', 'am' => 'የወተት ተዋጽኦና እንቁላል', 'sort' => 2],
        'sugar' => ['kind' => 'ingredient', 'en' => 'Sugar & sweeteners', 'am' => 'ስኳርና ጣፋጮች', 'sort' => 3],
        'fats' => ['kind' => 'ingredient', 'en' => 'Fats & oils', 'am' => 'ቅባትና ዘይት', 'sort' => 4],
        'meat' => ['kind' => 'ingredient', 'en' => 'Meat & poultry', 'am' => 'ሥጋና ዶሮ', 'sort' => 5],
        'vegetables' => ['kind' => 'ingredient', 'en' => 'Vegetables', 'am' => 'አትክልት', 'sort' => 6],
        'beverage_bases' => ['kind' => 'ingredient', 'en' => 'Beverage bases (coffee, tea, juice)', 'am' => 'የመጠጥ ግብዓቶች (ቡና፣ ሻይ፣ ጭማቂ)', 'sort' => 7],
        'packaging' => ['kind' => 'ingredient', 'en' => 'Packaging', 'am' => 'ማሸጊያ', 'sort' => 8],
        'breads' => ['kind' => 'product', 'en' => 'Breads', 'am' => 'ዳቦ', 'sort' => 1],
        'cakes' => ['kind' => 'product', 'en' => 'Cakes & pastries', 'am' => 'ኬክና ፓስትሪ', 'sort' => 2],
        'sandwiches' => ['kind' => 'product', 'en' => 'Sandwiches', 'am' => 'ሳንድዊች', 'sort' => 3],
        'pizza' => ['kind' => 'product', 'en' => 'Pizza', 'am' => 'ፒዛ', 'sort' => 4],
        'hot_drinks' => ['kind' => 'product', 'en' => 'Hot drinks', 'am' => 'ትኩስ መጠጦች', 'sort' => 5],
        'cold_drinks' => ['kind' => 'product', 'en' => 'Cold drinks', 'am' => 'ቀዝቃዛ መጠጦች', 'sort' => 6],
    ];

    /** @var array<string, IngredientSpec> sku => ingredient; stock in the item's unit, bought into the store */
    public const array INGREDIENTS = [
        // Flour & grains
        'ING-FLR-WHT' => ['category' => 'flour', 'en' => 'Wheat flour', 'am' => 'የስንዴ ዱቄት', 'unit' => 'kg', 'stock' => ['qty' => '200', 'to' => ['bakery' => '50', 'kitchen' => '10']]],
        'ING-FLR-CAKE' => ['category' => 'flour', 'en' => 'Cake flour', 'am' => 'የኬክ ዱቄት', 'unit' => 'kg', 'stock' => ['qty' => '50', 'to' => ['cake_room' => '10']]],
        'ING-YEAST' => ['category' => 'flour', 'en' => 'Dry yeast', 'am' => 'ደረቅ እርሾ', 'unit' => 'kg', 'stock' => ['qty' => '5', 'to' => ['bakery' => '1', 'kitchen' => '0.25']]],
        'ING-BKPWD' => ['category' => 'flour', 'en' => 'Baking powder', 'am' => 'ቤኪንግ ፓውደር', 'unit' => 'kg', 'stock' => ['qty' => '2', 'to' => ['cake_room' => '0.5']]],
        'ING-SALT' => ['category' => 'flour', 'en' => 'Salt', 'am' => 'ጨው', 'unit' => 'kg', 'stock' => ['qty' => '25', 'to' => ['bakery' => '3', 'kitchen' => '1']]],
        // Dairy & eggs
        'ING-MILK' => ['category' => 'dairy', 'en' => 'Fresh milk', 'am' => 'ትኩስ ወተት', 'unit' => 'L', 'stock' => ['qty' => '120', 'to' => ['bar' => '30', 'bakery' => '10', 'cake_room' => '5']]],
        'ING-EGG' => ['category' => 'dairy', 'en' => 'Eggs', 'am' => 'እንቁላል', 'unit' => 'pcs', 'stock' => ['qty' => '360', 'to' => ['kitchen' => '60', 'bakery' => '60', 'cake_room' => '60']]],
        'ING-CREAM' => ['category' => 'dairy', 'en' => 'Whipping cream', 'am' => 'የሚገረፍ ክሬም', 'unit' => 'L', 'stock' => ['qty' => '20', 'to' => ['cake_room' => '6']]],
        'ING-MOZZ' => ['category' => 'dairy', 'en' => 'Mozzarella cheese', 'am' => 'ሞዛሬላ አይብ', 'unit' => 'kg', 'stock' => ['qty' => '15', 'to' => ['kitchen' => '5']]],
        'ING-CRMCHS' => ['category' => 'dairy', 'en' => 'Cream cheese', 'am' => 'ክሬም አይብ', 'unit' => 'kg', 'stock' => ['qty' => '8', 'to' => ['cake_room' => '3']]],
        'ING-CHEDDAR' => ['category' => 'dairy', 'en' => 'Cheddar cheese', 'am' => 'ቼዳር አይብ', 'unit' => 'kg', 'stock' => ['qty' => '5', 'to' => ['kitchen' => '2']]],
        // Sugar & sweeteners
        'ING-SUGAR' => ['category' => 'sugar', 'en' => 'Sugar', 'am' => 'ስኳር', 'unit' => 'kg', 'stock' => ['qty' => '100', 'to' => ['bar' => '10', 'bakery' => '10', 'cake_room' => '10']]],
        'ING-ICING' => ['category' => 'sugar', 'en' => 'Icing sugar', 'am' => 'የዱቄት ስኳር', 'unit' => 'kg', 'stock' => ['qty' => '10', 'to' => ['cake_room' => '3']]],
        'ING-COCOA' => ['category' => 'sugar', 'en' => 'Cocoa powder', 'am' => 'የኮኮዋ ዱቄት', 'unit' => 'kg', 'stock' => ['qty' => '5', 'to' => ['cake_room' => '1.5', 'bar' => '0.5']]],
        'ING-CHOC' => ['category' => 'sugar', 'en' => 'Dark chocolate', 'am' => 'ጥቁር ቸኮሌት', 'unit' => 'kg', 'stock' => ['qty' => '10', 'to' => ['cake_room' => '4']]],
        'ING-CHERRY' => ['category' => 'sugar', 'en' => 'Cherries in syrup', 'am' => 'ቼሪ በሽሮፕ', 'unit' => 'kg', 'stock' => ['qty' => '6', 'to' => ['cake_room' => '2']]],
        'ING-VANILLA' => ['category' => 'sugar', 'en' => 'Vanilla essence', 'am' => 'የቫኒላ ቅመም', 'unit' => 'L', 'stock' => ['qty' => '1', 'to' => ['cake_room' => '0.25']]],
        // Fats & oils
        'ING-BUTTER' => ['category' => 'fats', 'en' => 'Butter', 'am' => 'ቅቤ', 'unit' => 'kg', 'stock' => ['qty' => '40', 'to' => ['bakery' => '10', 'cake_room' => '5', 'kitchen' => '2']]],
        'ING-MARG' => ['category' => 'fats', 'en' => 'Margarine', 'am' => 'ማርጋሪን', 'unit' => 'kg', 'stock' => ['qty' => '30', 'to' => ['bakery' => '10']]],
        'ING-OIL' => ['category' => 'fats', 'en' => 'Vegetable oil', 'am' => 'የምግብ ዘይት', 'unit' => 'L', 'stock' => ['qty' => '40', 'to' => ['kitchen' => '10']]],
        'ING-OLIVE' => ['category' => 'fats', 'en' => 'Olive oil', 'am' => 'የወይራ ዘይት', 'unit' => 'L', 'stock' => ['qty' => '10', 'to' => ['kitchen' => '3']]],
        'ING-MAYO' => ['category' => 'fats', 'en' => 'Mayonnaise', 'am' => 'ማዮኔዝ', 'unit' => 'kg', 'stock' => ['qty' => '10', 'to' => ['kitchen' => '3']]],
        // Meat & poultry
        'ING-CHICKEN' => ['category' => 'meat', 'en' => 'Chicken breast', 'am' => 'የዶሮ ደረት ሥጋ', 'unit' => 'kg', 'stock' => ['qty' => '20', 'to' => ['kitchen' => '8']]],
        'ING-BEEF' => ['category' => 'meat', 'en' => 'Minced beef', 'am' => 'የተፈጨ የበሬ ሥጋ', 'unit' => 'kg', 'stock' => ['qty' => '15', 'to' => ['kitchen' => '6']]],
        'ING-TUNA' => ['category' => 'meat', 'en' => 'Tuna (canned)', 'am' => 'ቱና (የታሸገ)', 'unit' => 'kg', 'stock' => ['qty' => '10', 'to' => ['kitchen' => '3']]],
        'ING-SAUSAGE' => ['category' => 'meat', 'en' => 'Beef sausage', 'am' => 'የበሬ ሥጋ ሶሴጅ', 'unit' => 'kg', 'stock' => ['qty' => '8', 'to' => ['kitchen' => '3']]],
        // Vegetables
        'ING-TOMATO' => ['category' => 'vegetables', 'en' => 'Tomatoes', 'am' => 'ቲማቲም', 'unit' => 'kg', 'stock' => ['qty' => '30', 'to' => ['kitchen' => '10']]],
        'ING-ONION' => ['category' => 'vegetables', 'en' => 'Red onions', 'am' => 'ቀይ ሽንኩርት', 'unit' => 'kg', 'stock' => ['qty' => '25', 'to' => ['kitchen' => '8']]],
        'ING-LETTUCE' => ['category' => 'vegetables', 'en' => 'Lettuce', 'am' => 'ሰላጣ', 'unit' => 'kg', 'stock' => ['qty' => '8', 'to' => ['kitchen' => '3']]],
        'ING-PEPPER' => ['category' => 'vegetables', 'en' => 'Green peppers', 'am' => 'ቃሪያ', 'unit' => 'kg', 'stock' => ['qty' => '6', 'to' => ['kitchen' => '2']]],
        'ING-OLIVES' => ['category' => 'vegetables', 'en' => 'Black olives', 'am' => 'ጥቁር የወይራ ፍሬ', 'unit' => 'kg', 'stock' => ['qty' => '5', 'to' => ['kitchen' => '1.5']]],
        'ING-TOMSAUCE' => ['category' => 'vegetables', 'en' => 'Tomato sauce', 'am' => 'የቲማቲም ስልስ', 'unit' => 'kg', 'stock' => ['qty' => '12', 'to' => ['kitchen' => '4']]],
        // Beverage bases (coffee, tea, juice)
        'ING-ESPRESSO' => ['category' => 'beverage_bases', 'en' => 'Espresso coffee beans', 'am' => 'የኤስፕሬሶ ቡና ፍሬ', 'unit' => 'kg', 'stock' => ['qty' => '20', 'to' => ['bar' => '5']]],
        'ING-BUNA' => ['category' => 'beverage_bases', 'en' => 'Ground coffee (jebena)', 'am' => 'የጀበና ቡና (የተፈጨ)', 'unit' => 'kg', 'stock' => ['qty' => '10', 'to' => ['bar' => '3']]],
        'ING-TEA' => ['category' => 'beverage_bases', 'en' => 'Black tea leaves', 'am' => 'የሻይ ቅጠል', 'unit' => 'kg', 'stock' => ['qty' => '3', 'to' => ['bar' => '1']]],
        'ING-SPICE' => ['category' => 'beverage_bases', 'en' => 'Tea spices (cinnamon, clove)', 'am' => 'የሻይ ቅመም (ቀረፋ፣ ቅርንፉድ)', 'unit' => 'kg', 'stock' => ['qty' => '1', 'to' => ['bar' => '0.3']]],
        'ING-MANGO' => ['category' => 'beverage_bases', 'en' => 'Mangoes', 'am' => 'ማንጎ', 'unit' => 'kg', 'stock' => ['qty' => '40', 'to' => ['bar' => '15']]],
        'ING-AVOCADO' => ['category' => 'beverage_bases', 'en' => 'Avocados', 'am' => 'አቮካዶ', 'unit' => 'kg', 'stock' => ['qty' => '40', 'to' => ['bar' => '15']]],
        'ING-PAPAYA' => ['category' => 'beverage_bases', 'en' => 'Papayas', 'am' => 'ፓፓያ', 'unit' => 'kg', 'stock' => ['qty' => '30', 'to' => ['bar' => '10']]],
        // Packaging
        'ING-CUP-HOT' => ['category' => 'packaging', 'en' => 'Paper cup 8 oz', 'am' => 'የወረቀት ኩባያ (8 አውንስ)', 'unit' => 'pcs', 'stock' => ['qty' => '2000', 'to' => ['bar' => '300']]],
        'ING-CUP-COLD' => ['category' => 'packaging', 'en' => 'Plastic cup 12 oz', 'am' => 'የፕላስቲክ ኩባያ (12 አውንስ)', 'unit' => 'pcs', 'stock' => ['qty' => '1000', 'to' => ['bar' => '200']]],
        'ING-BOX-CAKE' => ['category' => 'packaging', 'en' => 'Cake box', 'am' => 'የኬክ ሳጥን', 'unit' => 'pcs', 'stock' => ['qty' => '200', 'to' => ['cake_room' => '30']]],
        'ING-BOX-PIZZA' => ['category' => 'packaging', 'en' => 'Pizza box', 'am' => 'የፒዛ ሳጥን', 'unit' => 'pcs', 'stock' => ['qty' => '300', 'to' => ['kitchen' => '50']]],
    ];

    /** @var list<LineSpec> one 24 cm black forest cake */
    private const array BLACK_FOREST = [
        ['ING-FLR-CAKE', '300', 'g'],
        ['ING-COCOA', '60', 'g'],
        ['ING-SUGAR', '350', 'g'],
        ['ING-EGG', '8', 'pcs'],
        ['ING-BUTTER', '150', 'g'],
        ['ING-CREAM', '800', 'ml'],
        ['ING-CHERRY', '400', 'g'],
        ['ING-CHOC', '150', 'g', 'waste' => '5'],
        ['ING-BKPWD', '10', 'g'],
        ['ING-VANILLA', '10', 'ml'],
    ];

    /** @var list<array{0: string, 1: string}> */
    private const array BLACK_FOREST_STEPS = [
        ['Bake the chocolate sponge and cut it into three layers.', 'የቸኮሌት ስፖንጅ ኬኩን ጋግረው በሦስት ንብርብር ይቁረጡ።'],
        ['Fill with whipped cream and cherries; cover with cream and chocolate shavings.', 'በተገረፈ ክሬምና በቼሪ ሞልተው በክሬምና በተፈቀፈቀ ቸኮሌት ይሸፍኑ።'],
    ];

    /** @var list<LineSpec> the dough of one 30 cm pizza */
    private const array PIZZA_DOUGH = [
        ['ING-FLR-WHT', '250', 'g'],
        ['ING-YEAST', '5', 'g'],
        ['ING-SALT', '5', 'g'],
        ['ING-OLIVE', '15', 'ml'],
        ['ING-TOMSAUCE', '80', 'g'],
        ['ING-BOX-PIZZA', '1', 'pcs', 'optional' => true],
    ];

    /**
     * @var array<string, ProductSpec> sku => product; `route` is the station its order lines go to (none: sold from
     *                                 the display case), `produced_at` the station that makes a to-stock product
     */
    public const array PRODUCTS = [
        // Breads: baked in batches at the bakery
        'P-BRD-ROLL' => [
            'category' => 'breads', 'en' => 'Bread roll', 'am' => 'ዳቦ', 'unit' => 'pcs', 'fulfilment' => 'to_stock', 'price' => '15.00',
            'produced_at' => 'bakery', 'batch' => '120',
            'recipe' => ['yield' => ['120', 'pcs'], 'lines' => [
                ['ING-FLR-WHT', '7000', 'g', 'waste' => '2'], ['ING-YEAST', '70', 'g'], ['ING-SUGAR', '350', 'g'], ['ING-SALT', '120', 'g'], ['ING-MARG', '300', 'g'],
            ], 'steps' => [
                ['Mix the flour, yeast, sugar, salt and margarine with water and knead for 10 minutes.', 'ዱቄቱን፣ እርሾውን፣ ስኳሩን፣ ጨውንና ማርጋሪኑን ከውሃ ጋር ቀላቅለው ለ10 ደቂቃ ያቡኩ።'],
                ['Shape 100 g rolls, let them rise for 45 minutes and bake at 200 °C for 18 minutes.', '100 ግራም ዳቦዎችን ቅርጽ አውጥተው ለ45 ደቂቃ እንዲቦኩ ይተዉ፤ በ200 °C ለ18 ደቂቃ ይጋግሩ።'],
            ]],
            'stock' => ['qty' => '120', 'to' => ['display_case' => '80']],
        ],
        'P-BRD-TOAST' => [
            'category' => 'breads', 'en' => 'Toast bread (slice)', 'am' => 'የቶስት ዳቦ (ቁራጭ)', 'unit' => 'slice', 'fulfilment' => 'to_stock', 'price' => '10.00',
            'route' => 'kitchen', 'produced_at' => 'bakery', 'batch' => '120',
            'recipe' => ['yield' => ['120', 'slice'], 'lines' => [
                ['ING-FLR-WHT', '5000', 'g', 'waste' => '2'], ['ING-YEAST', '60', 'g'], ['ING-SUGAR', '250', 'g'], ['ING-SALT', '90', 'g'], ['ING-MARG', '250', 'g'], ['ING-MILK', '1000', 'ml'],
            ]],
            'stock' => ['qty' => '120', 'to' => ['kitchen' => '72']],
        ],
        'P-BRD-BAGUETTE' => [
            'category' => 'breads', 'en' => 'Baguette', 'am' => 'ባጌት', 'unit' => 'pcs', 'fulfilment' => 'to_stock', 'price' => '45.00',
            'produced_at' => 'bakery', 'batch' => '40',
            'recipe' => ['yield' => ['40', 'pcs'], 'lines' => [['ING-FLR-WHT', '6000', 'g', 'waste' => '2'], ['ING-YEAST', '60', 'g'], ['ING-SALT', '120', 'g']]],
            'stock' => ['qty' => '30', 'to' => ['display_case' => '20']],
        ],
        'P-BRD-MULMUL' => [
            'category' => 'breads', 'en' => 'Sweet bread (mulmul)', 'am' => 'ሙልሙል ዳቦ', 'unit' => 'pcs', 'fulfilment' => 'to_stock', 'price' => '25.00',
            'produced_at' => 'bakery', 'batch' => '60',
            'recipe' => ['yield' => ['60', 'pcs'], 'lines' => [
                ['ING-FLR-WHT', '4000', 'g'], ['ING-SUGAR', '600', 'g'], ['ING-YEAST', '50', 'g'], ['ING-BUTTER', '400', 'g'], ['ING-MILK', '1500', 'ml'], ['ING-EGG', '10', 'pcs'], ['ING-SALT', '40', 'g'],
            ]],
            'stock' => ['qty' => '60', 'to' => ['display_case' => '40']],
        ],
        'P-BRD-BUN' => [
            'category' => 'breads', 'en' => 'Burger bun', 'am' => 'የበርገር ዳቦ', 'unit' => 'pcs', 'fulfilment' => 'to_stock', 'price' => '20.00',
            'route' => 'kitchen', 'produced_at' => 'bakery', 'batch' => '48',
            'recipe' => ['yield' => ['48', 'pcs'], 'lines' => [
                ['ING-FLR-WHT', '3000', 'g'], ['ING-YEAST', '40', 'g'], ['ING-SUGAR', '200', 'g'], ['ING-SALT', '50', 'g'], ['ING-BUTTER', '200', 'g'], ['ING-MILK', '800', 'ml'], ['ING-EGG', '6', 'pcs'],
            ]],
            'stock' => ['qty' => '48', 'to' => ['kitchen' => '24']],
        ],
        // Cakes & pastries: the croissant at the bakery, cakes in the cake room
        'P-PST-CROISSANT' => [
            'category' => 'cakes', 'en' => 'Croissant', 'am' => 'ክሮሳንት', 'unit' => 'pcs', 'fulfilment' => 'to_stock', 'price' => '65.00',
            'produced_at' => 'bakery', 'batch' => '36',
            'recipe' => ['yield' => ['36', 'pcs'], 'lines' => [
                ['ING-FLR-WHT', '2500', 'g'], ['ING-BUTTER', '1400', 'g'], ['ING-SUGAR', '250', 'g'], ['ING-YEAST', '40', 'g'], ['ING-SALT', '50', 'g'], ['ING-MILK', '700', 'ml'], ['ING-EGG', '4', 'pcs'],
            ]],
            'stock' => ['qty' => '36', 'to' => ['display_case' => '24']],
        ],
        'P-CAK-BF-SLICE' => [
            'category' => 'cakes', 'en' => 'Black forest cake (slice)', 'am' => 'ብላክ ፎረስት ኬክ (ቁራጭ)', 'unit' => 'slice', 'fulfilment' => 'to_stock', 'price' => '180.00',
            'produced_at' => 'cake_room', 'batch' => '24',
            'recipe' => ['yield' => ['12', 'slice'], 'lines' => self::BLACK_FOREST, 'steps' => [...self::BLACK_FOREST_STEPS, ['Cut the cake into 12 slices.', 'ኬኩን በ12 ቁራጭ ይቁረጡ።']]],
            'stock' => ['qty' => '24', 'to' => ['display_case' => '12']],
        ],
        'P-CAK-BF-WHOLE' => [
            'category' => 'cakes', 'en' => 'Black forest cake (whole)', 'am' => 'ብላክ ፎረስት ኬክ (ሙሉ)', 'unit' => 'pcs', 'fulfilment' => 'to_stock', 'price' => '1900.00',
            'produced_at' => 'cake_room', 'batch' => '2',
            'recipe' => ['yield' => ['1', 'pcs'], 'lines' => [...self::BLACK_FOREST, ['ING-BOX-CAKE', '1', 'pcs']], 'steps' => self::BLACK_FOREST_STEPS],
            'stock' => ['qty' => '3', 'to' => ['display_case' => '2']],
        ],
        'P-CAK-CHOC-SLICE' => [
            'category' => 'cakes', 'en' => 'Chocolate cake (slice)', 'am' => 'የቸኮሌት ኬክ (ቁራጭ)', 'unit' => 'slice', 'fulfilment' => 'to_stock', 'price' => '170.00',
            'produced_at' => 'cake_room', 'batch' => '24',
            'recipe' => ['yield' => ['12', 'slice'], 'lines' => [
                ['ING-FLR-CAKE', '350', 'g'], ['ING-COCOA', '80', 'g'], ['ING-SUGAR', '400', 'g'], ['ING-EGG', '6', 'pcs'], ['ING-BUTTER', '200', 'g'],
                ['ING-MILK', '250', 'ml'], ['ING-CHOC', '250', 'g', 'waste' => '5'], ['ING-CREAM', '300', 'ml'], ['ING-BKPWD', '12', 'g'],
            ]],
            'stock' => ['qty' => '24', 'to' => ['display_case' => '12']],
        ],
        'P-CAK-CHEESE' => [
            'category' => 'cakes', 'en' => 'Cheesecake (slice)', 'am' => 'ቺዝ ኬክ (ቁራጭ)', 'unit' => 'slice', 'fulfilment' => 'to_stock', 'price' => '220.00',
            'produced_at' => 'cake_room', 'batch' => '12', 'new' => true,
            'recipe' => ['yield' => ['12', 'slice'], 'lines' => [
                ['ING-CRMCHS', '900', 'g'], ['ING-SUGAR', '250', 'g'], ['ING-EGG', '5', 'pcs'], ['ING-CREAM', '250', 'ml'], ['ING-BUTTER', '120', 'g'],
                ['ING-FLR-CAKE', '200', 'g'], ['ING-VANILLA', '10', 'ml'], ['ING-ICING', '50', 'g'],
            ]],
            'stock' => ['qty' => '12', 'to' => ['display_case' => '8']],
        ],
        // Sandwiches: made to order in the kitchen
        'P-SND-CHICKEN' => [
            'category' => 'sandwiches', 'en' => 'Chicken sandwich', 'am' => 'የዶሮ ሳንድዊች', 'unit' => 'pcs', 'fulfilment' => 'to_order', 'price' => '420.00', 'route' => 'kitchen',
            'recipe' => ['yield' => ['1', 'pcs'], 'lines' => [
                ['P-BRD-TOAST', '2', 'slice'], ['ING-CHICKEN', '120', 'g', 'waste' => '5'], ['ING-LETTUCE', '20', 'g'], ['ING-TOMATO', '40', 'g'], ['ING-MAYO', '15', 'g'], ['ING-BUTTER', '10', 'g'],
            ], 'steps' => [
                ['Toast two slices of bread and spread them with butter.', 'ሁለት ቁራጭ ዳቦ ጠብሰው ቅቤ ይቀቡ።'],
                ['Grill the chicken, slice it and layer it with lettuce, tomato and mayonnaise.', 'ዶሮውን ጠብሰው ቆራርጠው ከሰላጣ፣ ከቲማቲምና ከማዮኔዝ ጋር ይደርድሩ።'],
            ]],
        ],
        'P-SND-TUNA' => [
            'category' => 'sandwiches', 'en' => 'Tuna sandwich', 'am' => 'የቱና ሳንድዊች', 'unit' => 'pcs', 'fulfilment' => 'to_order', 'price' => '380.00', 'route' => 'kitchen',
            'recipe' => ['yield' => ['1', 'pcs'], 'lines' => [
                ['P-BRD-TOAST', '2', 'slice'], ['ING-TUNA', '80', 'g'], ['ING-ONION', '15', 'g'], ['ING-MAYO', '20', 'g'], ['ING-LETTUCE', '15', 'g'], ['ING-TOMATO', '30', 'g'],
            ]],
        ],
        'P-SND-CLUB' => [
            'category' => 'sandwiches', 'en' => 'Club sandwich', 'am' => 'ክለብ ሳንድዊች', 'unit' => 'pcs', 'fulfilment' => 'to_order', 'price' => '520.00', 'route' => 'kitchen',
            'recipe' => ['yield' => ['1', 'pcs'], 'lines' => [
                ['P-BRD-TOAST', '3', 'slice'], ['ING-CHICKEN', '100', 'g', 'waste' => '5'], ['ING-EGG', '1', 'pcs'], ['ING-CHEDDAR', '20', 'g'], ['ING-TOMATO', '40', 'g'],
                ['ING-LETTUCE', '20', 'g'], ['ING-MAYO', '20', 'g'], ['ING-BUTTER', '10', 'g'],
            ]],
        ],
        'P-SND-BURGER' => [
            'category' => 'sandwiches', 'en' => 'Beef burger', 'am' => 'የበሬ ሥጋ በርገር', 'unit' => 'pcs', 'fulfilment' => 'to_order', 'price' => '550.00', 'route' => 'kitchen',
            'recipe' => ['yield' => ['1', 'pcs'], 'lines' => [
                ['P-BRD-BUN', '1', 'pcs'], ['ING-BEEF', '150', 'g', 'waste' => '5'], ['ING-CHEDDAR', '20', 'g'], ['ING-TOMATO', '30', 'g'], ['ING-LETTUCE', '15', 'g'],
                ['ING-ONION', '20', 'g'], ['ING-MAYO', '15', 'g'], ['ING-OIL', '10', 'ml'],
            ]],
        ],
        // Pizza: made to order in the kitchen
        'P-PZA-MARGH' => [
            'category' => 'pizza', 'en' => 'Margherita pizza', 'am' => 'ማርጋሪታ ፒዛ', 'unit' => 'pcs', 'fulfilment' => 'to_order', 'price' => '650.00', 'route' => 'kitchen',
            'recipe' => ['yield' => ['1', 'pcs'], 'lines' => [...self::PIZZA_DOUGH, ['ING-MOZZ', '150', 'g'], ['ING-TOMATO', '50', 'g']], 'steps' => [
                ['Stretch the dough to 30 cm and spread the tomato sauce.', 'ሊጡን 30 ሳ.ሜ. አስፍተው የቲማቲም ስልሱን ይቀቡ።'],
                ['Top with mozzarella and sliced tomato; bake at 250 °C for 8 minutes.', 'ሞዛሬላና የተከተፈ ቲማቲም ጨምረው በ250 °C ለ8 ደቂቃ ይጋግሩ።'],
            ]],
        ],
        'P-PZA-SPECIAL' => [
            'category' => 'pizza', 'en' => 'Special pizza', 'am' => 'ስፔሻል ፒዛ', 'unit' => 'pcs', 'fulfilment' => 'to_order', 'price' => '850.00', 'route' => 'kitchen',
            'recipe' => ['yield' => ['1', 'pcs'], 'lines' => [
                ...self::PIZZA_DOUGH, ['ING-MOZZ', '180', 'g'], ['ING-SAUSAGE', '80', 'g'], ['ING-BEEF', '60', 'g'], ['ING-PEPPER', '30', 'g'], ['ING-ONION', '30', 'g'], ['ING-OLIVES', '25', 'g'],
            ]],
        ],
        // Hot drinks: made to order at the bar
        'P-HOT-MACCHIATO' => [
            'category' => 'hot_drinks', 'en' => 'Macchiato', 'am' => 'ማኪያቶ', 'unit' => 'pcs', 'fulfilment' => 'to_order', 'price' => '60.00', 'route' => 'bar',
            'recipe' => ['yield' => ['1', 'pcs'], 'lines' => [['ING-ESPRESSO', '9', 'g'], ['ING-MILK', '60', 'ml'], ['ING-SUGAR', '10', 'g', 'optional' => true]], 'steps' => [
                ['Pull a single espresso shot into a glass and top it with foamed milk.', 'አንድ ኤስፕሬሶ በብርጭቆ አውጥተው በአረፋ ወተት ይሙሉት።'],
            ]],
        ],
        'P-HOT-LATTE' => [
            'category' => 'hot_drinks', 'en' => 'Latte', 'am' => 'ላቴ', 'unit' => 'pcs', 'fulfilment' => 'to_order', 'price' => '90.00', 'route' => 'bar',
            'recipe' => ['yield' => ['1', 'pcs'], 'lines' => [['ING-ESPRESSO', '18', 'g'], ['ING-MILK', '220', 'ml'], ['ING-CUP-HOT', '1', 'pcs'], ['ING-SUGAR', '10', 'g', 'optional' => true]], 'steps' => [
                ['Pull a double espresso shot (18 g).', 'ድርብ ኤስፕሬሶ (18 ግራም) ያውጡ።'],
                ['Steam the milk and pour it over the espresso.', 'ወተቱን በእንፋሎት አሙቀው በኤስፕሬሶው ላይ ያፍሱ።'],
            ]],
        ],
        'P-HOT-CAPPUCCINO' => [
            'category' => 'hot_drinks', 'en' => 'Cappuccino', 'am' => 'ካፑቺኖ', 'unit' => 'pcs', 'fulfilment' => 'to_order', 'price' => '95.00', 'route' => 'bar',
            'recipe' => ['yield' => ['1', 'pcs'], 'lines' => [['ING-ESPRESSO', '18', 'g'], ['ING-MILK', '150', 'ml'], ['ING-CUP-HOT', '1', 'pcs'], ['ING-COCOA', '2', 'g', 'optional' => true]]],
        ],
        'P-HOT-BUNA' => [
            'category' => 'hot_drinks', 'en' => 'Ethiopian coffee (buna)', 'am' => 'የሀበሻ ቡና', 'unit' => 'pcs', 'fulfilment' => 'to_order', 'price' => '40.00', 'route' => 'bar',
            'recipe' => ['yield' => ['1', 'pcs'], 'lines' => [['ING-BUNA', '12', 'g'], ['ING-SUGAR', '10', 'g', 'optional' => true]], 'steps' => [
                ['Brew the ground coffee in the jebena and serve it in a sini cup.', 'የተፈጨውን ቡና በጀበና አፍልተው በስኒ ያቅርቡ።'],
            ]],
        ],
        'P-HOT-SHAI' => [
            'category' => 'hot_drinks', 'en' => 'Tea (shai)', 'am' => 'ሻይ', 'unit' => 'pcs', 'fulfilment' => 'to_order', 'price' => '30.00', 'route' => 'bar',
            'recipe' => ['yield' => ['1', 'pcs'], 'lines' => [['ING-TEA', '3', 'g'], ['ING-SPICE', '1', 'g'], ['ING-SUGAR', '10', 'g', 'optional' => true]]],
        ],
        // Cold drinks: fresh juices made to order at the bar, bottles bought in and sold from the display case fridge
        'P-CLD-SPRIS' => [
            'category' => 'cold_drinks', 'en' => 'Spris (layered juice)', 'am' => 'ስፕሪስ', 'unit' => 'pcs', 'fulfilment' => 'to_order', 'price' => '180.00', 'route' => 'bar', 'new' => true,
            'recipe' => ['yield' => ['1', 'pcs'], 'lines' => [
                ['ING-AVOCADO', '120', 'g', 'waste' => '30'], ['ING-MANGO', '120', 'g', 'waste' => '25'], ['ING-PAPAYA', '120', 'g', 'waste' => '25'], ['ING-SUGAR', '15', 'g'],
                ['ING-CUP-COLD', '1', 'pcs', 'optional' => true],
            ], 'steps' => [
                ['Blend each fruit on its own and layer avocado, mango and papaya in a tall glass.', 'እያንዳንዱን ፍራፍሬ ለየብቻ ፈጭተው አቮካዶ፣ ማንጎና ፓፓያን በረጅም ብርጭቆ በንብርብር ያድርጉ።'],
            ]],
        ],
        'P-CLD-MANGO' => [
            'category' => 'cold_drinks', 'en' => 'Mango juice', 'am' => 'የማንጎ ጭማቂ', 'unit' => 'pcs', 'fulfilment' => 'to_order', 'price' => '150.00', 'route' => 'bar',
            'recipe' => ['yield' => ['1', 'pcs'], 'lines' => [['ING-MANGO', '300', 'g', 'waste' => '25'], ['ING-SUGAR', '15', 'g'], ['ING-CUP-COLD', '1', 'pcs', 'optional' => true]]],
        ],
        'P-CLD-AVOCADO' => [
            'category' => 'cold_drinks', 'en' => 'Avocado juice', 'am' => 'የአቮካዶ ጭማቂ', 'unit' => 'pcs', 'fulfilment' => 'to_order', 'price' => '160.00', 'route' => 'bar',
            'recipe' => ['yield' => ['1', 'pcs'], 'lines' => [['ING-AVOCADO', '250', 'g', 'waste' => '30'], ['ING-SUGAR', '20', 'g'], ['ING-CUP-COLD', '1', 'pcs', 'optional' => true]]],
        ],
        'P-CLD-PAPAYA' => [
            'category' => 'cold_drinks', 'en' => 'Papaya juice', 'am' => 'የፓፓያ ጭማቂ', 'unit' => 'pcs', 'fulfilment' => 'to_order', 'price' => '140.00', 'route' => 'bar',
            'recipe' => ['yield' => ['1', 'pcs'], 'lines' => [['ING-PAPAYA', '300', 'g', 'waste' => '25'], ['ING-SUGAR', '10', 'g'], ['ING-CUP-COLD', '1', 'pcs', 'optional' => true]]],
        ],
        'P-CLD-WATER' => [
            'category' => 'cold_drinks', 'en' => 'Bottled water 0.5 L', 'am' => 'የታሸገ ውሃ 0.5 ሊትር', 'unit' => 'pcs', 'fulfilment' => 'bought_in', 'price' => '30.00',
            'stock' => ['qty' => '240', 'to' => ['display_case' => '48']],
        ],
        'P-CLD-COLA' => [
            'category' => 'cold_drinks', 'en' => 'Cola 300 ml', 'am' => 'ኮላ 300 ሚሊ ሊትር', 'unit' => 'pcs', 'fulfilment' => 'bought_in', 'price' => '50.00',
            'stock' => ['qty' => '144', 'to' => ['display_case' => '48']],
        ],
        'P-CLD-ORANGE' => [
            'category' => 'cold_drinks', 'en' => 'Orange soda 300 ml', 'am' => 'የብርቱካን ለስላሳ 300 ሚሊ ሊትር', 'unit' => 'pcs', 'fulfilment' => 'bought_in', 'price' => '50.00',
            'stock' => ['qty' => '96', 'to' => ['display_case' => '24']],
        ],
    ];

    /**
     * One person per role and a second waiter, so the shared pad's short-code switching can be shown. Names are given in
     * both scripts and stored as "English (Amharic)" in users.name (a person's name has no translation column).
     * Stations: [code, can_count, is_overseer]; the cake baker co-signs the bakery's counts (the overseer flag).
     * The numbers are made up (+251 900 000 1xx).
     *
     * @var list<PersonSpec>
     */
    public const array PEOPLE = [
        ['role' => 'admin', 'en' => 'Dawit Bekele', 'am' => 'ዳዊት በቀለ', 'phone' => self::ADMIN_PHONE, 'locale' => 'en', 'stations' => []],
        ['role' => 'manager', 'en' => 'Meron Tadesse', 'am' => 'ሜሮን ታደሰ', 'phone' => '+251900000102', 'locale' => 'en', 'stations' => [['store', true, false]]],
        ['role' => 'cashier', 'en' => 'Selam Getachew', 'am' => 'ሰላም ጌታቸው', 'phone' => '+251900000103', 'locale' => 'am', 'stations' => [['till', true, false]]],
        ['role' => 'waiter', 'en' => 'Yonas Alemu', 'am' => 'ዮናስ አለሙ', 'phone' => '+251900000104', 'locale' => 'am', 'stations' => []],
        ['role' => 'waiter', 'en' => 'Hana Worku', 'am' => 'ሐና ወርቁ', 'phone' => '+251900000105', 'locale' => 'am', 'stations' => []],
        ['role' => 'chef', 'en' => 'Biniam Haile', 'am' => 'ቢንያም ኃይሌ', 'phone' => '+251900000106', 'locale' => 'am', 'stations' => [['kitchen', true, false]]],
        ['role' => 'barista', 'en' => 'Tigist Mekonnen', 'am' => 'ትዕግሥት መኮንን', 'phone' => '+251900000107', 'locale' => 'am', 'stations' => [['bar', true, false]]],
        ['role' => 'cake_baker', 'en' => 'Hirut Desta', 'am' => 'ሂሩት ደስታ', 'phone' => '+251900000108', 'locale' => 'am', 'stations' => [['cake_room', true, false], ['bakery', false, true]]],
        ['role' => 'bread_baker', 'en' => 'Abebe Girma', 'am' => 'አበበ ግርማ', 'phone' => '+251900000109', 'locale' => 'am', 'stations' => [['bakery', true, false]]],
    ];

    /** @return array<string, array{requires_overseer: bool, sort: int}> */
    public static function stations(): array
    {
        return self::STATIONS;
    }

    /** @return array<string, array{en: string, am: string, station: string}> */
    public static function locations(): array
    {
        return self::LOCATIONS;
    }

    /** @return array<string, array{kind: string, en: string, am: string, sort: int}> */
    public static function categories(): array
    {
        return self::CATEGORIES;
    }

    /** @return array<string, IngredientSpec> */
    public static function ingredients(): array
    {
        return self::INGREDIENTS;
    }

    /** @return array<string, ProductSpec> */
    public static function products(): array
    {
        return self::PRODUCTS;
    }

    /** @return list<PersonSpec> */
    public static function people(): array
    {
        return self::PEOPLE;
    }

    /** @param  PersonSpec  $person */
    public static function nameOf(array $person): string
    {
        return "{$person['en']} ({$person['am']})";
    }
}
