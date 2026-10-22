<?php

declare(strict_types=1);

/*
 * Amharic messages for errors raised by App\Modules\Shifts (message_key = shifts.<group>.<name>); the twin of
 * lang/en/shifts.php (rule 9), kept in step by tests/Modules/Shifts/ShiftsLangTest.php.
 */
return [
    'station' => [
        'forbidden' => 'ፈረቃን መጠቀም የሚችሉት በሚሰሩበት ጣቢያ ብቻ ነው።',
        'not_tracked' => 'ይህ ጣቢያ ፈረቃ አይሰራም።',
    ],
    'shift' => [
        'already_open' => 'በዚህ ጣቢያ ፈረቃ አስቀድሞ እየሰራ ነው። በምትኩ ይቀላቀሉት።',
        'already_scheduled' => 'ይህ ፈረቃ ለዚያ ጣቢያና ቀን አስቀድሞ ተመድቧል።',
        'not_scheduled' => 'ይህ ፈረቃ ለመጀመር የሚጠብቅ አይደለም።',
        'outside_window' => 'ይህ ፈረቃ አሁን ሊጀመር አይችልም። ከመጀመሪያ ሰዓቱ እስከ መጨረሻ ሰዓቱ ይሰራል።',
        'not_running' => 'ይህ ፈረቃ እየሰራ አይደለም።',
        'not_cancellable' => 'ያልጀመረ ወይም አሁን እየጀመረ ያለ ፈረቃ ብቻ ሊሰረዝ ይችላል።',
    ],
    'start' => [
        'force_forbidden' => 'ፈረቃን ከሰዓቱ ውጭ መጀመር የሚችለው አስተዳዳሪው ብቻ ነው።',
    ],
    'cancel' => [
        'forbidden' => 'ይህን ፈረቃ መሰረዝ የሚችሉት አስተዳዳሪው ወይም ፈረቃውን እየጀመረ ያለው ሰው ብቻ ናቸው።',
    ],
    'template' => [
        'window_invalid' => 'ፈረቃ በአንድ ሰዓት ሊጀምርና ሊያልቅ አይችልም።',
        'station_unknown' => 'ከጣቢያዎቹ አንዱ የዚህ ቅርንጫፍ ጣቢያ አይደለም።',
        'not_for_station' => 'ይህ የፈረቃ ቅጽ በዚህ ጣቢያ አይገኝም።',
    ],
    'roster' => [
        'user_invalid' => 'በፈረቃ ላይ ያሉ ሁሉ በጣቢያው የሚሰሩ ንቁ ሰዎች መሆን አለባቸው።',
    ],
];
