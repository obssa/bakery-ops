<?php

declare(strict_types=1);

/*
 * Amharic messages for errors raised by the purchase endpoints of App\Modules\Inventory (message_key = purchases.<group>.<name>);
 * the English twins are in lang/en/purchases.php (rule 9).
 */
return [
    'supplier' => [
        'unknown' => 'ይህ አቅራቢ የለም፣ ጠፍቷል ወይም የሌላ ቅርንጫፍ ነው።',
    ],
    'location' => [
        'unknown' => 'ዕቃው የሚቀበልበት ቦታ በዚህ ቅርንጫፍ የለም። መጋዘን ያዘጋጁ ወይም ቦታ ይምረጡ።',
    ],
    'item' => [
        'not_purchasable' => 'መግዛት የሚቻለው ግብዓቶችንና የሚገዙ ምርቶችን ብቻ ነው፤ ዕቃውም በዚህ ቅርንጫፍ ንቁ መሆን አለበት።',
    ],
    'unit' => [
        'incompatible' => 'ይህ መለኪያ ከዕቃው ጋር አንድ ዓይነት ነገር አይለካም፤ ስለዚህ መቀየር አይቻልም።',
    ],
    'line' => [
        'cost_too_small' => 'ይህ ዋጋ ለመያዝ በጣም ትንሽ ነው፤ በመሠረታዊ መለኪያ ሲሰላ ዜሮ ይሆናል።',
    ],
    'invoice' => [
        'duplicate' => 'ይህ አቅራቢ በዚህ የደረሰኝ ቁጥር ቀድሞ ግዢ አለው። የተለየ ርክክብ መሆኑን አረጋግጠው እንደገና ይመዝግቡ።',
    ],
    'purchase' => [
        'not_draft' => 'የሚለወጠው ረቂቅ ግዢ ብቻ ነው።',
        'cancelled' => 'ይህ ግዢ ተሰርዟል፤ ማረጋገጥ አይቻልም።',
        'confirmed' => 'የተረጋገጠ ግዢ መሰረዝ አይቻልም። በምትኩ ክምችቱን በማስተካከያ ያስተካክሉ።',
        'empty' => 'ምንም መስመር የሌለው ግዢ ማረጋገጥ አይቻልም።',
    ],
    'cost' => [
        'currency_mismatch' => 'የዚህ ዕቃ ዋጋ የሚያዘው በሌላ ገንዘብ ነው፤ ስለዚህ ይህን ግዢ ማስላት አይቻልም።',
    ],
    'validation' => [
        'too_large' => 'ይህ ዋጋ በጣም ትልቅ ነው።',
    ],
];
