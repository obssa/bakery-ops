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
];
