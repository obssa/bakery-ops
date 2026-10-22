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
];
