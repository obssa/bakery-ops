<?php

declare(strict_types=1);

/*
 * Amharic messages for App\Modules\Payments. Same key set as lang/en/payments.php (rule 9).
 */
return [
    'channel' => [
        'unknown' => 'ይህ የክፍያ ዘዴ የለም።',
        'disabled' => 'ይህ የክፍያ ዘዴ ጠፍቷል።',
        'photo_required' => 'ይህ የክፍያ ዘዴ የደረሰኝ ፎቶ ያስፈልገዋል፤ ይህ ደግሞ ገና አይቻልም። አሁን በጥሬ ገንዘብ ይቀበሉ።',
        'reference_required' => 'የዝውውሩን ማጣቀሻ ቁጥር ይጻፉ።',
    ],
    'amount' => [
        'mismatch' => 'መጠኑ የትዕዛዙ ሙሉ ቀሪ ሂሳብ መሆን አለበት። ከፊል ክፍያና ከልክ በላይ ክፍያ አይቀበልም።',
    ],
    'tendered' => [
        'too_low' => 'የተሰጠው ጥሬ ገንዘብ ከሚከፈለው መጠን ያንሳል።',
    ],
    'order' => [
        'not_payable' => 'ይህ ትዕዛዝ ሊከፈል አይችልም፤ መላክ አለበት፤ ደግሞም የተሰረዘ፣ የተሻረ ወይም የተዘጋ መሆን የለበትም።',
        'settled' => 'ይህ ትዕዛዝ አስቀድሞ ተከፍሏል። የመጨረሻው ዕቃ ሲቀርብ ይዘጋል።',
    ],
    'client_uuid' => [
        'reused' => 'ይህ የክፍያ መለያ ለሌላ ትዕዛዝ አስቀድሞ ጥቅም ላይ ውሏል።',
    ],
];
