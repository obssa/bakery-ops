<?php

declare(strict_types=1);

/*
 * Amharic messages for errors raised by App\Modules\Production (message_key = production.<group>.<name>). Every key has an
 * English twin in lang/en/production.php (rule 9); tests/Modules/Production/ProductionRequestsLangTest.php keeps them in step.
 */
return [
    'plan' => [
        'required' => 'የመጠናቀቂያ ጊዜ ወይም ከተለመደው ውጭ ያለ ቅድሚያ ማስቀመጥ የሚችለው እቅድ አውጪ ብቻ ነው።',
    ],
    'station' => [
        'forbidden' => 'የምርት ጥያቄዎችን መውሰድ የሚችሉት በሚሠሩበት ጣቢያ ብቻ ነው።',
    ],
    'cancel' => [
        'forbidden' => 'ይህን ምርት መሰረዝ የሚችሉት እቅድ አውጪው ወይም ጥያቄውን ያቀረበው ሰው ብቻ ነው።',
    ],
    'request' => [
        'already_open' => 'ለዚህ ምርት ክፍት የምርት ጥያቄ አስቀድሞ አለ። እሱን ይጠቀሙ።',
        'taken' => 'ይህ የምርት ጥያቄ ለሌላ ሰው ተሰጥቷል ወይም በሌላ ሰው ተወስዷል።',
        'in_progress' => 'ይህ ምርት ተጀምሯል። ያጠናቅቁት ወይም ይመልሱት፤ መሰረዝ አይቻልም።',
        'not_accepted' => 'ይህን የምርት ጥያቄ ከመጀመርዎ በፊት ይቀበሉት።',
    ],
    'locations' => [
        'unresolved' => 'የዚህ ጣቢያ ወይም ምርት የክምችት ቦታ ገና አልተዘጋጀም። ከመጀመርዎ በፊት አስተዳዳሪውን ያነጋግሩ።',
    ],
    'batch' => [
        'ingredient_short' => 'በ:place በቂ :ingredient የለም፤ :short :unit ይጎድላል።:more',
        'more_short' => ' ሌሎች ንጥረ ነገሮችም ይጎድላሉ።',
        'self_confirm' => 'ምርቱን ያጠናቀቀው ሰው ራሱ ማረጋገጥ አይችልም። ሌላ ሰው እንዲያረጋግጥ ይጠይቁ።',
    ],
    'confirm' => [
        'forbidden' => 'ምርትን ማረጋገጥ ወይም መመለስ የሚችሉት አስተዳዳሪ፣ ሥራ አስኪያጅ ወይም የዚህ ጣቢያ ተቆጣጣሪ ብቻ ናቸው።',
    ],
    'product' => [
        'not_producible' => 'በክምችት የሚቀመጥ እና ንቁ የምግብ አዘገጃጀት ያለው ምርት ብቻ ነው ሊመረት የሚችለው።',
        'no_station' => 'ይህን ምርት የሚያዘጋጅ ጣቢያ የለም። መጀመሪያ የማምረቻ ጣቢያውን ያስቀምጡ።',
    ],
    'assignee' => [
        'invalid' => 'ይህ ሰው ይህን ጥያቄ ማዘጋጀት አይችልም። በጣቢያው የሚሠራ እና ምርት ማከናወን የሚችል ንቁ ሰው ይምረጡ።',
    ],
    'validation' => [
        'quantity' => 'መጠኑ ከዜሮ በላይ እና እስከ አራት የአስርዮሽ ቁጥሮች ያለው ቁጥር መሆን አለበት።',
        'batch_quantity' => 'ብዛቱ ዜሮ ወይም ከዚያ በላይ የሆነ ቁጥር መሆን አለበት፤ ከአራት የአስርዮሽ ቦታዎች አይበልጥም።',
        'batch_empty' => 'አንድ ምርት አንድ ነገር ያመረተ ወይም ያባከነ መሆን አለበት፤ ሁለቱም ዜሮ ሊሆኑ አይችሉም።',
    ],
];
