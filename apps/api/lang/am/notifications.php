<?php

declare(strict_types=1);

/*
 * Amharic texts of the inbox lines: the twin of lang/en/notifications.php, same keys and same :name placeholders (rule 9).
 */
return [
    'text' => [
        'stock_out' => [
            'title' => ':item አልቋል',
            'body' => 'በ:place ምንም አልቀረም።',
        ],
        'stock_low' => [
            'title' => 'እያለቀ ነው፡ :item',
            'body' => 'በ:place :available ብቻ ቀርቷል (ዝቅተኛው :min)። :reorder ያዝዙ።',
        ],
        'transfer_requested' => [
            'title' => 'ክምችት ተጠይቋል፡ :item',
            'body' => ':item፣ :qty :unit፣ ከ:from ወደ :to።',
        ],
        'transfer_decided' => [
            'confirmed' => [
                'title' => 'ዝውውር ተረጋግጧል፡ :item',
                'body' => ':item፣ :qty :unit ከ:from ወደ :to ተዛውሯል።',
            ],
            'rejected' => [
                'title' => 'ዝውውር ተቀባይነት አላገኘም፡ :item',
                'body' => 'የ:qty :unit :item ጥያቄ ውድቅ ተደርጓል። ምክንያት፡ :reason',
            ],
        ],
        'production_requested' => [
            'title' => 'እባክዎ ያዘጋጁ፡ :item',
            'body' => ':qty :unit :item በ:station ተጠይቋል።',
            'body_stock_out' => ':item አልቋል። :qty :unit በ:station ያዘጋጁ።',
        ],
        'production_assigned' => [
            'title' => 'ለእርስዎ ተሰጥቷል፡ :item',
            'body' => ':qty :unit :item በ:station ያዘጋጁ።',
        ],
        'batch_pending_confirmation' => [
            'title' => 'ማረጋገጫ ይጠብቃል፡ :item',
            'body' => ':produced :unit :item በ:station ተዘጋጅቷል፣ :waste :unit ባከነ። ያረጋግጡ ወይም ይመልሱ።',
        ],
        'batch_done' => [
            'title' => 'ምርት ተጠናቀቀ፡ :item',
            'body' => ':produced :unit :item በ:station ተዘጋጅቷል፣ :waste :unit ባከነ።',
        ],
        'line_ready' => [
            'title' => 'ተዘጋጅቷል፡ :item',
            'body' => 'ትዕዛዝ #:order_no፣ ጠረጴዛ :table፡ :item ለማቅረብ ዝግጁ ነው።',
            'body_no_table' => 'ትዕዛዝ #:order_no፡ :item ለማቅረብ ዝግጁ ነው።',
        ],
        'line_rejected' => [
            'title' => 'ተመልሷል፡ :item',
            'body' => 'ትዕዛዝ #:order_no፣ ጠረጴዛ :table፡ :item ተመልሷል። ምክንያት፡ :reason',
            'body_no_table' => 'ትዕዛዝ #:order_no፡ :item ተመልሷል። ምክንያት፡ :reason',
        ],
    ],

    // ደረጃ 2.5b፡ ያልበራ የማሳወቂያ መንገድ ሲጠየቅ የሚሰጥ መልስ፣ እና የቴሌግራም ቦት የሚመልሳቸው አጫጭር መልዕክቶች።
    'driver' => [
        'disabled' => 'ይህ የማሳወቂያ መንገድ ለዚህ ዳቦ ቤት አልበራም።',
    ],
    'telegram' => [
        'linked' => 'ተገናኝቷል። የዳቦ ቤቱ ማሳሰቢያዎች እዚህ ይደርሱዎታል።',
        'unknown_code' => 'ኮዱ ትክክል አይደለም ወይም ጊዜው አልፎበታል። መተግበሪያውን ከፍተው አዲስ ኮድ ይጠይቁ።',
        'help' => 'ይህን ውይይት ለማገናኘት በመተግበሪያው ውስጥ ኮድ ጠይቀው እዚህ ይላኩ፦ /start ኮድ',
    ],
];
