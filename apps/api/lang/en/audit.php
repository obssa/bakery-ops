<?php

declare(strict_types=1);

/*
 * English texts of App\Modules\Audit (message_key = audit.<group>.<name>; step 4.5a): the export's refusal, the note an xlsx cell holds in
 * place of a JSON too long for it, and the export's column headers. Every key has an Amharic twin in lang/am/audit.php (rule 9);
 * tests/Modules/Audit/AuditLangTest.php keeps them in step. The numbers a refusal is about travel beside it in the body (max_rows, rows).
 */
return [
    'export' => [
        'too_large' => 'Too many rows for one file. Choose fewer days, an action or a person.',
        'too_long' => 'Too long for a spreadsheet cell: read row :id in the audit log, or export as CSV.',
        'columns' => [
            'id' => 'ID',
            'at' => 'Time',
            'actor_id' => 'Actor ID',
            'actor' => 'Actor',
            'acting_user_id' => 'Acting user ID',
            'acting_user' => 'Acting user',
            'action' => 'Action',
            'subject_type' => 'Subject type',
            'subject_id' => 'Subject ID',
            'station_id' => 'Station ID',
            'shift_id' => 'Shift ID',
            'device_id' => 'Device ID',
            'ip' => 'IP address',
            'changed' => 'Changed fields',
            'reason' => 'Reason',
            'before' => 'Before',
            'after' => 'After',
            'context' => 'Context',
        ],
    ],
];
