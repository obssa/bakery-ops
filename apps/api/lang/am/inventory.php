<?php

declare(strict_types=1);

/*
 * Amharic messages for errors raised by App\Modules\Inventory. Same key set as lang/en/inventory.php (rule 9).
 */
return [
    'stock' => [
        'insufficient' => 'በዚህ ቦታ የዚህ እቃ በቂ ክምችት የለም።',
        'reserved_below_zero' => 'ይህ እዚህ ለእቃው ከተያዘው በላይ ይለቃል።',
    ],
    'reservation' => [
        'not_found' => 'በዚህ መለያ የተያዘ ቦታ ማስያዣ የለም።',
        'released' => 'ይህ ማስያዣ ተለቋል፤ ከእንግዲህ መጠቀም አይቻልም።',
        'closed' => 'ይህ ማስያዣ ቀድሞ ተጠናቋል፤ በአዲስ መለያ እንደገና ያስይዙ።',
    ],
    'recipe' => [
        'missing' => 'ይህ ምርት በትዕዛዝ የሚዘጋጅ ቢሆንም እስካሁን የአዘገጃጀት መመሪያ የለውም፤ ስለዚህ ግብዓቶቹ ሊያዙ አይችሉም።',
    ],
    'movement' => [
        'client_uuid_reused' => 'ይህ መዝገብ ቀድሞ በተለየ ዝርዝር ተቀምጧል።',
    ],
    'cost' => [
        'exists' => 'ይህ ዕቃ ቀድሞ ዋጋ አለው። የመነሻ ዋጋ ገና ዋጋ ላልተመዘገበለት ክምችት ብቻ ነው።',
        'not_found' => 'ለዚህ ዕቃ እስካሁን የተመዘገበ ዋጋ የለም።',
    ],
];
