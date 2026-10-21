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
    ],
];
