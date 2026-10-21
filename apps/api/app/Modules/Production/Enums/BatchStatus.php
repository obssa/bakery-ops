<?php

declare(strict_types=1);

namespace App\Modules\Production\Enums;

use App\Shared\StateMachine\StateMachine;

/**
 * The production batch machine (phase-2 plan section 3, migration 16; section 6 item 7):
 * started -> finished; finished -> confirmed | started; confirmed is final.
 *
 * - started: the baker pressed Start; the recipe version and both places are fixed.
 * - finished: produced and waste are recorded and an overseer has not yet signed off; nothing is in the ledger.
 *   An overseer's send back returns it to started (send_back_count + 1, the note on the card).
 * - confirmed: the consumption and the output were written together. Final: a mistake is a new movement, never an undo (rule 4).
 *   A station that needs no overseer goes started -> finished -> confirmed inside the one write of Done.
 *
 * The production_batches table repeats the values in the CHECK production_batches_status_check. Every pair not listed in
 * transitions() throws IllegalTransition.
 */
enum BatchStatus: string
{
    use StateMachine;

    case Started = 'started';
    case Finished = 'finished';
    case Confirmed = 'confirmed';

    /** @return array<string, list<self>> */
    public static function transitions(): array
    {
        return [
            self::Started->value => [self::Finished],
            self::Finished->value => [self::Confirmed, self::Started],
            self::Confirmed->value => [],
        ];
    }
}
