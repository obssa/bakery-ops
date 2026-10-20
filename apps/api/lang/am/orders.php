<?php

declare(strict_types=1);

/*
 * Amharic messages for App\Modules\Orders. Same key set as lang/en/orders.php (rule 9).
 */
return [
    'send' => [
        'shortfall' => 'አንዳንድ ዝርዝሮች ባለው ክምችት ሊዘጋጁ አይችሉም። ምንም አልተላከም፤ እነዚያን ዝርዝሮች ቀይረው እንደገና ይላኩ።',
        'shortfall_line' => ':item ×:qty ማከል አይቻልም፤ :ingredient :short ጎድሏል (:station)።',
        'shortfall_line_no_station' => ':item ×:qty ማከል አይቻልም፤ :ingredient :short ጎድሏል።',
    ],
    'order' => [
        'not_open' => 'ይህ ትዕዛዝ ከእንግዲህ ክፍት አይደለም፤ ቀድሞ ተልኳል፣ ተሰርዟል ወይም ውድቅ ተደርጓል።',
        'not_owner' => 'ይህ የሌላ አስተናጋጅ ትዕዛዝ ነው። ሊቀይረው የሚችለው አስተናጋጁ ወይም ሥራ አስኪያጅ ብቻ ነው።',
        'empty' => 'ይህ ትዕዛዝ የሚላክ ዝርዝር የለውም።',
    ],
    'item' => [
        'not_sellable' => 'ይህ እቃ እዚህ ሊሸጥ አይችልም፤ የዚህ ቅርንጫፍ ንቁ ምርት አይደለም።',
        'no_price' => 'ይህ ምርት እስካሁን ዋጋ የለውም። አስተዳዳሪውን ዋጋ እንዲያስገባ ይጠይቁ።',
        'no_recipe' => 'ይህ ምርት በትዕዛዝ የሚዘጋጅ ቢሆንም እስካሁን ንቁ የአዘገጃጀት መመሪያ የለውም፤ ስለዚህ ግብዓቶቹ ሊያዙ አይችሉም።',
        'no_location' => 'ይህ ምርት እስካሁን የሚዘጋጅበት ጣቢያ ወይም የሚወሰድበት የክምችት ቦታ የለውም። አስተዳዳሪውን እንዲያዘጋጅ ይጠይቁ።',
    ],
    'modifier' => [
        'not_of_item' => 'ይህ ምርጫ የዚህ ምርት አይደለም።',
    ],
    'table' => [
        'unknown' => 'ይህ የዚህ ቅርንጫፍ ክፍት ጠረጴዛ አይደለም።',
    ],
    'line' => [
        'changed' => 'አንድ ዝርዝር ምርቱንና ምርጫዎቹን አይቀይርም። ሌላ ነገር ለማዘዝ እንደ አዲስ ዝርዝር ያክሉት።',
    ],
];
