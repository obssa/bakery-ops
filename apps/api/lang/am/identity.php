<?php

declare(strict_types=1);

/*
 * Amharic messages for errors raised by App\Modules\Identity. Same key set as lang/en/identity.php (rule 9).
 */
return [
    'login' => [
        'invalid' => 'የስልክ ቁጥሩ ወይም የይለፍ ቃሉ ትክክል አይደለም።',
    ],
    'branch' => [
        'required' => 'የትኛውን ቅርንጫፍ እንደሆነ ይግለጹ (branch_id)።',
    ],
    'user' => [
        'inactive' => 'ይህ መለያ ተዘግቷል፤ አስተዳዳሪውን ይጠይቁ።',
    ],
    'device' => [
        'required' => 'ይህ መሣሪያ የ X-Device-Id ራስጌውን መላክ አለበት።',
        'revoked' => 'ይህ መሣሪያ ተሰርዟል፤ አስተዳዳሪው እንደገና እንዲመዘግበው ይጠይቁ።',
    ],
    'acting_session' => [
        'expired' => 'የአጭር ኮድ ክፍለ ጊዜዎ አብቅቷል፤ ኮድዎን እንደገና ያስገቡ።',
    ],
    'short_code' => [
        'format' => 'አጭር ኮድ ከ4 እስከ 6 አሃዞች ነው።',
        'taken' => 'በዚህ ቅርንጫፍ ሌላ ሰው ያንን አጭር ኮድ አስቀድሞ ይጠቀማል።',
        'invalid' => 'ያ አጭር ኮድ ትክክል አይደለም።',
        'locked' => 'በጣም ብዙ የተሳሳቱ ኮዶች፤ ከጥቂት ደቂቃዎች በኋላ እንደገና ይሞክሩ።',
    ],
    'pin' => [
        'rejected' => 'ያ ፒን ይህን ለማጽደቅ የተፈቀደለት ሰው አይደለም።',
    ],
];
