<?php

declare(strict_types=1);

/*
 * English messages for errors raised by App\Modules\Shifts (message_key = shifts.<group>.<name>). Every key has an Amharic twin in
 * lang/am/shifts.php (rule 9); tests/Modules/Shifts/ShiftsLangTest.php keeps them in step.
 */
return [
    'station' => [
        'forbidden' => 'You can use shifts only at the station you work at.',
        'not_tracked' => 'This station does not run shifts.',
    ],
    'shift' => [
        'already_open' => 'A shift is already running at this station. Join it instead.',
        'already_scheduled' => 'This shift is already scheduled for that station and day.',
        'not_scheduled' => 'This shift is not waiting to be started.',
        'outside_window' => 'This shift cannot be started now. It runs from its start time to its end time.',
        'not_running' => 'This shift is not running.',
        'not_open' => 'Only a shift that is open can be closed.',
        'not_cancellable' => 'Only a shift that has not started, or is just starting, can be cancelled.',
    ],
    'start' => [
        'force_forbidden' => 'Only the admin can start a shift outside its time.',
    ],
    'cancel' => [
        'forbidden' => 'Only the admin, or the person who is starting this shift, can cancel it.',
    ],
    'template' => [
        'window_invalid' => 'A shift cannot start and end at the same time.',
        'station_unknown' => 'One of the stations is not a station of this branch.',
        'not_for_station' => 'This shift template is not available at this station.',
    ],
    'roster' => [
        'user_invalid' => 'Everyone on a shift must be an active person who works at its station.',
    ],
    'count' => [
        'not_draft' => 'This count has already been handed in, so it can no longer be changed.',
        'item_unknown' => 'This item cannot be counted here.',
        'qty_invalid' => 'The counted amount must be a number that is not negative, with at most four decimals.',
        'incomplete' => 'Every line must be checked before the count is handed in, and every item that has stock must be on the count.',
        'reason_required' => 'Choose a reason for every line whose counted amount differs from the expected amount.',
        'note_required' => 'The reason you chose needs a short note. Write what happened.',
        'blocked_by_pending' => 'An earlier count at this place is still waiting for a decision. Only one count at a place can wait at a time.',
        'shift_not_ready' => 'This shift is not taking this count right now.',
        'not_pending' => 'This count is not waiting for a decision.',
        'approve_self' => 'You counted this yourself, so someone else must approve it. You can reject it and count again.',
        'cosign_forbidden' => 'You cannot co-sign this count. Only the overseer of this station, an admin or a manager can.',
        'cosign_self' => 'You counted this yourself, so someone else must co-sign it. You can reject it and count again.',
        'not_awaiting_cosign' => 'This count is not waiting for a co-signature.',
        'item_unavailable' => 'An item on this count was removed from the catalog. Reject the count and count again without it.',
    ],
    'reason' => [
        'unknown' => 'This reason does not exist or is switched off.',
        'code_taken' => 'A reason with this code already exists.',
    ],
    'adjustment' => [
        'insufficient' => 'The correction would leave less than nothing in stock, because stock left after the count. Reject the count and count again.',
    ],
];
