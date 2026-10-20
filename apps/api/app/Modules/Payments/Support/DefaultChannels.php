<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use Illuminate\Support\Facades\DB;

/**
 * The eight payment channels every branch starts with (phase-1 plan section 3, migration 7): cash, which needs nothing
 * more, and the seven digital ones (wallets and banks), which need the receipt photo and the bank's reference (Phase 4
 * builds the photo upload; Payments refuses them until then). Names are bilingual (rule 9), sorted 1 to 8 as listed.
 * Written with INSERT IGNORE on the unique (branch_id, code): giving a branch its defaults twice changes nothing, and a
 * channel an admin edited, switched off or removed is never put back or changed. The migration that creates the table
 * keeps its own copy of this list (migrations must not depend on application classes); DefaultChannelsTest keeps them equal.
 */
final class DefaultChannels
{
    /** @var list<array{code: string, name_en: string, name_am: string, requires_photo: bool, requires_reference: bool}> */
    public const array LIST = [
        ['code' => 'cash', 'name_en' => 'Cash', 'name_am' => 'ጥሬ ገንዘብ', 'requires_photo' => false, 'requires_reference' => false],
        ['code' => 'telebirr', 'name_en' => 'Telebirr', 'name_am' => 'ቴሌብር', 'requires_photo' => true, 'requires_reference' => true],
        ['code' => 'cbe_birr', 'name_en' => 'CBE Birr', 'name_am' => 'ሲቢኢ ብር', 'requires_photo' => true, 'requires_reference' => true],
        ['code' => 'cbe_bank', 'name_en' => 'CBE bank transfer', 'name_am' => 'የንግድ ባንክ ዝውውር', 'requires_photo' => true, 'requires_reference' => true],
        ['code' => 'awash', 'name_en' => 'Awash Bank', 'name_am' => 'አዋሽ ባንክ', 'requires_photo' => true, 'requires_reference' => true],
        ['code' => 'dashen', 'name_en' => 'Dashen Bank', 'name_am' => 'ዳሽን ባንክ', 'requires_photo' => true, 'requires_reference' => true],
        ['code' => 'abyssinia', 'name_en' => 'Bank of Abyssinia', 'name_am' => 'የአቢሲኒያ ባንክ', 'requires_photo' => true, 'requires_reference' => true],
        ['code' => 'coop', 'name_en' => 'Cooperative Bank of Oromia', 'name_am' => 'የኦሮሚያ ህብረት ሥራ ባንክ', 'requires_photo' => true, 'requires_reference' => true],
    ];

    /**
     * Give the branch the channels it lacks; no branch needs to be resolved, the id is explicit.
     *
     * @return int how many rows were written (0 when the branch had them all, removed ones included)
     */
    public static function ensureFor(int $branchId): int
    {
        $now = now()->utc()->format('Y-m-d H:i:s');
        $rows = [];
        foreach (self::LIST as $i => $channel) {
            $rows[] = $channel + ['branch_id' => $branchId, 'is_enabled' => true, 'sort' => $i + 1, 'created_at' => $now, 'updated_at' => $now];
        }

        return DB::table('payment_channels')->insertOrIgnore($rows);
    }
}
