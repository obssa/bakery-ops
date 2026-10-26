<?php

declare(strict_types=1);

/*
 * English messages for App\Modules\Analytics (message_key = analytics.<group>.<name>). Every key has an Amharic twin in lang/am/analytics.php
 * (rule 9); tests/Modules/Analytics/AnalyticsLangTest.php keeps them in step. The numbers a message is about travel beside it in the body
 * (max_days, today, from, to).
 */
return [
    'range' => [
        'too_large' => 'This range has too many days. The dashboards read at most 400 days, a rebuild here and now at most 7, a rebuild on the queue at most 31. Choose a shorter range.',
        'future' => 'The range goes past today. Choose days up to today.',
    ],
    'rebuild' => [
        'running' => 'A rebuild of some of these days is already waiting on the queue. Wait a minute or two for it to finish, then ask again.',
    ],
];
