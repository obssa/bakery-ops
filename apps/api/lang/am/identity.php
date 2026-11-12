<?php

declare(strict_types=1);

/*
 * Amharic messages for errors raised by App\Modules\Identity. Same key set as lang/en/identity.php (rule 9).
 */
return [
    'login' => [
        'invalid' => 'የስልክ ቁጥሩ ወይም የይለፍ ቃሉ ትክክል አይደለም።',
    ],
    'demo_login' => [
        'unavailable' => 'ለዚህ ሚና የማሳያ መለያ የለም።',
        'not_demo_branch' => 'የማሳያ መግቢያ ቁልፎችን ማብራትና ማጥፋት የሚችለው የማሳያ ዳቦ ቤቱ አስተዳዳሪ ብቻ ነው።',
    ],
    'branch' => [
        'required' => 'የትኛውን ቅርንጫፍ እንደሆነ ይግለጹ (branch_id)።',
    ],
    'user' => [
        'inactive' => 'ይህ መለያ ተዘግቷል፤ አስተዳዳሪውን ይጠይቁ።',
    ],
    'device' => [
        'required' => 'ይህ መሣሪያ የ X-Device-Id ራስጌውን መላክ አለበት።',
        'revoked' => 'ይህ መሣሪያ ተሰርዟል፤ አስተዳዳሪው ታብሌቱን እንደ አዲስ መሣሪያ እንዲመዘግበው ይጠይቁ።',
        'unknown' => 'ይህ ታብሌት አልተመዘገበም፤ አስተዳዳሪው በዚህ ማያ ገጽ ላይ እንዲመዘግበው ይጠይቁ።',
        'other_branch' => 'ይህ ታብሌት በሌላ ቅርንጫፍ ተመዝግቧል፤ አስተዳዳሪውን ይጠይቁ።',
        'fingerprint_taken' => 'ይህ መሣሪያ አስቀድሞ ተመዝግቧል።',
        'kind_invalid' => 'መሣሪያ ታብሌት፣ የክፍያ ቦታ፣ የወጥ ቤት ማያ ገጽ ወይም የህትመት ድልድይ ነው።',
    ],
    // step 5.3a: the print bridge signs in with its own token (Authorization: Bridge <token>)
    'bridge' => [
        'unknown' => 'ይህ የህትመት ድልድይ አልተመዘገበም፣ ወይም ቶከኑ ትክክል አይደለም። እንደ ድልድይ መሣሪያ ይመዝግቡትና የተሰጠውን ቶከን ያስገቡ።',
        'revoked' => 'ይህ የህትመት ድልድይ ተሰርዟል። አስተዳዳሪው እንደ አዲስ መሣሪያ እንዲመዘግበው ይጠይቁ፣ አዲሱንም ቶከን ያስገቡ።',
    ],
    'acting_session' => [
        'expired' => 'የአጭር ኮድ ክፍለ ጊዜዎ አብቅቷል፤ ኮድዎን እንደገና ያስገቡ።',
    ],
    'channel' => [
        'forbidden' => 'ይህን የቀጥታ ቻናል መከታተል አይፈቀድልዎትም።',
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
