<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

/**
 * Phase 2 of the demo bakery as data (step 2.9; phase-2 plan section 8): suppliers, opening costs, purchases, reorder rules, the
 * gate product, version 2 of the bread roll, transfers and production. Phase2Seeder writes it through the module code the API
 * uses and never changes what DemoBakery holds (Phase 0 and 1): its only new catalog row is the gate product.
 *
 * Quantities are decimal strings in the unit named next to them (an item's own unit unless a unit code is given); costs are ETB
 * per that unit. Names, phone numbers and invoice numbers are made up (+251 900 000 2xx). People are named by role: the demo bakery
 * has one admin, manager, chef, cake baker and bread baker (DemoBakery::PEOPLE).
 *
 * @phpstan-type PurchaseSpec array{supplier: string, invoice: string, confirm: bool, notes: string, lines: list<array{0: string, 1: string, 2: string}>}
 * @phpstan-type TransferSpec array{item: string, to: string, qty: string, by: string, decide: string|null, reason?: string}
 * @phpstan-type RequestSpec array{product: string, qty: string, reason: string, priority: string, due: string|null, accept: bool, finish: bool, confirm: bool}
 */
final class DemoPhaseTwo
{
    /** The gate product (plan section 8): "Honey cake (slice)", one slice on hand at the display case. */
    public const string GATE_SKU = 'P-CAK-HONEY-SLICE';

    /** @var array<string, array{name: string, phone: string, tin: string|null, notes: string}> key => supplier; names are proper nouns, shown as typed */
    public const array SUPPLIERS = [
        'mill' => ['name' => 'Selam Flour Mill', 'phone' => '+251900000201', 'tin' => '0000000201', 'notes' => 'Flour and baking goods; delivers on Mondays and Thursdays.'],
        'dairy' => ['name' => 'Habesha Dairy', 'phone' => '+251900000202', 'tin' => '0000000202', 'notes' => 'Milk, eggs and butter; delivers every morning.'],
        'packaging' => ['name' => 'Zenith Packaging', 'phone' => '+251900000203', 'tin' => '0000000203', 'notes' => 'Cups, cake boxes and pizza boxes.'],
    ];

    /**
     * Opening cost of every ingredient and bought-in product, ETB per the item's own unit (kg, L or pcs), what the shelf was
     * worth when the books started. The cost per base unit (a gram, a millilitre) is this divided by the unit's factor.
     *
     * @var array<string, string>
     */
    public const array OPENING_COSTS = [
        'ING-FLR-WHT' => '62.00', 'ING-FLR-CAKE' => '78.00', 'ING-YEAST' => '420.00', 'ING-BKPWD' => '260.00', 'ING-SALT' => '25.00',
        'ING-MILK' => '85.00', 'ING-EGG' => '14.00', 'ING-CREAM' => '560.00', 'ING-MOZZ' => '620.00', 'ING-CRMCHS' => '780.00', 'ING-CHEDDAR' => '900.00',
        'ING-SUGAR' => '95.00', 'ING-ICING' => '130.00', 'ING-COCOA' => '700.00', 'ING-CHOC' => '950.00', 'ING-CHERRY' => '480.00', 'ING-VANILLA' => '3200.00',
        'ING-BUTTER' => '820.00', 'ING-MARG' => '310.00', 'ING-OIL' => '260.00', 'ING-OLIVE' => '1100.00', 'ING-MAYO' => '310.00',
        'ING-CHICKEN' => '480.00', 'ING-BEEF' => '620.00', 'ING-TUNA' => '700.00', 'ING-SAUSAGE' => '520.00',
        'ING-TOMATO' => '60.00', 'ING-ONION' => '55.00', 'ING-LETTUCE' => '90.00', 'ING-PEPPER' => '80.00', 'ING-OLIVES' => '450.00', 'ING-TOMSAUCE' => '220.00',
        'ING-ESPRESSO' => '1800.00', 'ING-BUNA' => '1200.00', 'ING-TEA' => '600.00', 'ING-SPICE' => '1500.00', 'ING-MANGO' => '90.00', 'ING-AVOCADO' => '110.00', 'ING-PAPAYA' => '70.00',
        'ING-CUP-HOT' => '4.00', 'ING-CUP-COLD' => '5.00', 'ING-BOX-CAKE' => '18.00', 'ING-BOX-PIZZA' => '15.00',
        'P-CLD-WATER' => '12.00', 'P-CLD-COLA' => '28.00', 'P-CLD-ORANGE' => '28.00',
    ];

    /**
     * Two purchases received into the store and one left as a draft to confirm by hand. Lines: [SKU, quantity, ETB per unit]. The
     * flour is bought dearer than its opening cost, so the moving average visibly moves.
     *
     * @var array<string, PurchaseSpec>
     */
    public const array PURCHASES = [
        'flour' => ['supplier' => 'mill', 'invoice' => 'SFM-0412', 'confirm' => true, 'notes' => 'Monday delivery', 'lines' => [['ING-FLR-WHT', '100', '66.50'], ['ING-FLR-CAKE', '25', '80.00']]],
        'dairy' => ['supplier' => 'dairy', 'invoice' => 'HD-0907', 'confirm' => true, 'notes' => 'Morning delivery', 'lines' => [['ING-MILK', '60', '88.00'], ['ING-EGG', '180', '15.50'], ['ING-BUTTER', '20', '840.00']]],
        'packaging' => ['supplier' => 'packaging', 'invoice' => 'ZP-1130', 'confirm' => false, 'notes' => 'Waiting for the delivery note', 'lines' => [['ING-BOX-CAKE', '100', '17.50'], ['ING-CUP-HOT', '1000', '3.80']]],
    ];

    /**
     * Reorder rules: [SKU, place (a location type), minimum, quantity to reorder], both in the item's own unit; REORDER_ROLES are told. None of the
     * seeded stock is below its minimum, except the gate product at the display case (one slice against a minimum of three).
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public const array REORDER_RULES = [
        ['ING-FLR-WHT', 'store', '50', '100'],
        ['ING-SUGAR', 'store', '25', '50'],
        ['ING-BUTTER', 'store', '8', '20'],
        ['ING-MILK', 'store', '25', '60'],
        ['ING-EGG', 'store', '90', '180'],
        ['ING-CREAM', 'store', '5', '10'],
        ['ING-CREAM', 'cake_room', '2', '6'],
        [self::GATE_SKU, 'display_case', '3', '6'],
    ];

    /** @var list<string> roles told when a reorder rule fires; "station" is the people of the place's station */
    public const array REORDER_ROLES = ['admin', 'manager', 'station'];

    /** Version 2 of the bread roll: butter for the margarine, a little less sugar. */
    public const string ROLL_SKU = 'P-BRD-ROLL';

    public const string ROLL_V2_NOTE = 'Richer dough: butter instead of margarine, a little less sugar.';

    /** @var list<array{0: string, 1: string, 2: string, waste?: string}> [SKU, quantity, unit] */
    public const array ROLL_V2_LINES = [
        ['ING-FLR-WHT', '7000', 'g', 'waste' => '2'], ['ING-YEAST', '70', 'g'], ['ING-SUGAR', '300', 'g'], ['ING-SALT', '120', 'g'], ['ING-BUTTER', '300', 'g'],
    ];

    /**
     * The gate product, new in Phase 2, so no earlier seeded stock is lowered: a slice of honey cake sold from the display case and
     * made at the cake room in batches of 6, from 12-slice recipes over what the cake room already holds.
     *
     * @var array{category: string, en: string, am: string, unit: string, price: string, batch: string, station: string, stock: string, yield: array{0: string, 1: string}, lines: list<array{0: string, 1: string, 2: string}>, steps: list<array{0: string, 1: string}>}
     */
    public const array GATE_PRODUCT = [
        'category' => 'cakes', 'en' => 'Honey cake (slice)', 'am' => 'የማር ኬክ (ቁራጭ)', 'unit' => 'slice', 'price' => '150.00', 'batch' => '6', 'station' => 'cake_room', 'stock' => '1',
        'yield' => ['12', 'slice'],
        'lines' => [
            ['ING-FLR-CAKE', '250', 'g'], ['ING-SUGAR', '200', 'g'], ['ING-EGG', '4', 'pcs'], ['ING-BUTTER', '150', 'g'],
            ['ING-CREAM', '200', 'ml'], ['ING-BKPWD', '8', 'g'], ['ING-VANILLA', '5', 'ml'],
        ],
        'steps' => [
            ['Cream the butter and sugar, beat in the eggs, then fold in the flour, baking powder and vanilla.', 'ቅቤውንና ስኳሩን ይምቱ፤ እንቁላሉን ጨምረው ዱቄቱን፣ ቤኪንግ ፓውደሩንና ቫኒላውን ይደባልቁ።'],
            ['Bake at 180 °C for 35 minutes, cool, cover with whipped cream and cut into 12 slices.', 'በ180 °C ለ35 ደቂቃ ጋግረው ያቀዘቅዙ፤ በተገረፈ ክሬም ሸፍነው በ12 ቁራጭ ይቁረጡ።'],
        ],
    ];

    /**
     * Three transfers out of the store: one confirmed by the manager, one left for the store to act on, one rejected with a reason.
     * `to` is a location type, `by` the role that asks (it must work at the destination), `decide` the manager's decision or null.
     *
     * @var array<string, TransferSpec>
     */
    public const array TRANSFERS = [
        'butter-bakery' => ['item' => 'ING-BUTTER', 'to' => 'bakery', 'qty' => '5', 'by' => 'bread_baker', 'decide' => 'confirm'],
        'eggs-kitchen' => ['item' => 'ING-EGG', 'to' => 'kitchen', 'qty' => '60', 'by' => 'chef', 'decide' => null],
        'cream-cake-room' => ['item' => 'ING-CREAM', 'to' => 'cake_room', 'qty' => '5', 'by' => 'cake_baker', 'decide' => 'reject', 'reason' => 'The cream is held for Saturday\'s wedding cake order; ask again on Monday.'],
    ];

    /**
     * Production, as the manager plans it and the bakers work it. `due` is a day offset and a time in the branch's zone ("+1 06:00" is
     * 06:00 tomorrow). `accept` / `finish` / `confirm` say how far the baker (and then the manager) take it.
     *
     * @var array<string, RequestSpec>
     */
    public const array REQUESTS = [
        'baguette-tomorrow' => ['product' => 'P-BRD-BAGUETTE', 'qty' => '80', 'reason' => 'schedule', 'priority' => 'high', 'due' => '+1 06:00', 'accept' => false, 'finish' => false, 'confirm' => false],
        'rolls-accepted' => ['product' => 'P-BRD-ROLL', 'qty' => '120', 'reason' => 'manual', 'priority' => 'normal', 'due' => null, 'accept' => true, 'finish' => false, 'confirm' => false],
        'rolls-waiting' => ['product' => 'P-BRD-ROLL', 'qty' => '120', 'reason' => 'schedule', 'priority' => 'normal', 'due' => '+0 20:00', 'accept' => true, 'finish' => true, 'confirm' => false],
        'choc-cake-done' => ['product' => 'P-CAK-CHOC-SLICE', 'qty' => '12', 'reason' => 'manual', 'priority' => 'normal', 'due' => null, 'accept' => true, 'finish' => true, 'confirm' => true],
    ];

    /** @return array<string, array{name: string, phone: string, tin: string|null, notes: string}> */
    public static function suppliers(): array
    {
        return self::SUPPLIERS;
    }

    /** @return array<string, string> */
    public static function openingCosts(): array
    {
        return self::OPENING_COSTS;
    }

    /** @return array<string, PurchaseSpec> */
    public static function purchases(): array
    {
        return self::PURCHASES;
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string}> */
    public static function reorderRules(): array
    {
        return self::REORDER_RULES;
    }

    /** @return array<string, TransferSpec> */
    public static function transfers(): array
    {
        return self::TRANSFERS;
    }

    /** @return array<string, RequestSpec> */
    public static function requests(): array
    {
        return self::REQUESTS;
    }
}
