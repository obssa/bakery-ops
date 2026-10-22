<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Support;

use Illuminate\Support\Facades\DB;

/**
 * The five variance reasons every branch starts with (phase-3 plan section 3, migration 8; architecture section 4.4 point 2):
 * spoilage, breakage, miscount, suspected theft and other (which asks for a note). Names are bilingual (rule 9), sorted 1 to 5 as
 * listed; the Amharic texts are a first draft for Phase 5's review with the staff. Written with INSERT IGNORE on the unique
 * (branch_id, code): giving a branch its defaults twice changes nothing, and a reason an admin edited, switched off or removed is
 * never put back or changed. The migration that creates the table keeps its own copy of this list (migrations must not depend on
 * application classes that change later); DefaultVarianceReasonsTest keeps them equal.
 */
final class DefaultVarianceReasons
{
    /** @var list<array{code: string, name_en: string, name_am: string, requires_note: bool}> */
    public const array LIST = [
        ['code' => 'spoilage', 'name_en' => 'Spoilage', 'name_am' => 'መበላሸት', 'requires_note' => false],
        ['code' => 'breakage', 'name_en' => 'Breakage', 'name_am' => 'መሰበር', 'requires_note' => false],
        ['code' => 'miscount', 'name_en' => 'Miscount', 'name_am' => 'የቆጠራ ስህተት', 'requires_note' => false],
        ['code' => 'theft_suspected', 'name_en' => 'Theft suspected', 'name_am' => 'የስርቆት ጥርጣሬ', 'requires_note' => false],
        ['code' => 'other', 'name_en' => 'Other', 'name_am' => 'ሌላ', 'requires_note' => true],
    ];

    /**
     * Give the branch the reasons it lacks; no branch needs to be resolved, the id is explicit.
     *
     * @return int how many rows were written (0 when the branch had them all, removed ones included)
     */
    public static function ensureFor(int $branchId): int
    {
        $now = now()->utc()->format('Y-m-d H:i:s');
        $rows = [];
        foreach (self::LIST as $i => $reason) {
            $rows[] = $reason + ['branch_id' => $branchId, 'is_active' => true, 'sort' => $i + 1, 'version' => 1, 'created_at' => $now, 'updated_at' => $now];
        }

        return DB::table('variance_reasons')->insertOrIgnore($rows);
    }
}
