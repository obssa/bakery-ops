<?php

declare(strict_types=1);

/*
 * The message keys step 3.3a adds to lang/{en,am}/shifts.php, in one place so that ShiftsLangTest (the keys of step 3.2b) and
 * CountsLangTest (these) can together say that no key lacks a test and no test lacks a key.
 */

if (! function_exists('countsMessageKeys')) {
    /** @return list<string> */
    function countsMessageKeys(): array
    {
        return [
            'shifts.shift.not_open',
            'shifts.count.not_draft', 'shifts.count.item_unknown', 'shifts.count.qty_invalid', 'shifts.count.incomplete',
            'shifts.count.reason_required', 'shifts.count.note_required', 'shifts.count.blocked_by_pending', 'shifts.count.shift_not_ready',
            'shifts.count.not_pending', 'shifts.count.approve_self',
            'shifts.count.cosign_forbidden', 'shifts.count.cosign_self', 'shifts.count.not_awaiting_cosign', 'shifts.count.item_unavailable',
            'shifts.reason.unknown', 'shifts.reason.code_taken',
            'shifts.adjustment.insufficient',
        ];
    }
}
