<?php

declare(strict_types=1);

/*
 * የ App\Modules\Audit የአማርኛ ጽሑፎች (step 4.5a): the Amharic twin of lang/en/audit.php, the same keys (tests/Modules/Audit/AuditLangTest.php).
 */
return [
    'export' => [
        'too_large' => 'ለአንድ ፋይል በጣም ብዙ ረድፎች ናቸው። ያነሱ ቀናት፣ አንድ ተግባር ወይም አንድ ሰው ይምረጡ።',
        'too_long' => 'ለአንድ የተመን ሉህ ሕዋስ በጣም ረጅም ነው፦ በኦዲት መዝገቡ ውስጥ ረድፍ :id ን ያንብቡ፣ ወይም እንደ CSV ያውጡ።',
        'columns' => [
            'id' => 'መለያ',
            'at' => 'ሰዓት',
            'actor_id' => 'የፈጻሚው መለያ',
            'actor' => 'ፈጻሚ',
            'acting_user_id' => 'በጋራ መሣሪያ የሠራው ሰው መለያ',
            'acting_user' => 'በጋራ መሣሪያ የሠራው ሰው',
            'action' => 'ተግባር',
            'subject_type' => 'የጉዳዩ ዓይነት',
            'subject_id' => 'የጉዳዩ መለያ',
            'station_id' => 'የጣቢያ መለያ',
            'shift_id' => 'የፈረቃ መለያ',
            'device_id' => 'የመሣሪያ መለያ',
            'ip' => 'የአይፒ አድራሻ',
            'changed' => 'የተቀየሩ መስኮች',
            'reason' => 'ምክንያት',
            'before' => 'ከለውጡ በፊት',
            'after' => 'ከለውጡ በኋላ',
            'context' => 'ተጨማሪ መረጃ',
        ],
    ],
];
