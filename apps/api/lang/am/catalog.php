<?php

declare(strict_types=1);

/*
 * Amharic messages for errors raised by App\Modules\Catalog. Same key set as lang/en/catalog.php (rule 9).
 */
return [
    'item' => [
        'not_a_product' => 'የአዘገጃጀት መመሪያ፣ ጣቢያ እና ማሻሻያ ያላቸው ምርቶች ብቻ ናቸው።',
        'in_use' => 'ንቁ የሆነ የአዘገጃጀት መመሪያ አሁንም ይህን እቃ ይጠቀማል፤ መጀመሪያ መመሪያውን ይቀይሩ።',
    ],
    'category' => [
        'in_use' => 'ይህ ምድብ አሁንም እቃዎችን ወይም ንዑስ ምድቦችን ይዟል፤ መጀመሪያ ያዛውሯቸው።',
        'cycle' => 'አንድ ምድብ በራሱ ወይም በራሱ ንዑስ ምድብ ውስጥ ሊቀመጥ አይችልም።',
    ],
    'unit' => [
        'incompatible' => 'ያ መለኪያ ይህ እቃ ወደሚቆጠርበት መለኪያ አይቀየርም።',
    ],
    'price' => [
        'too_early' => 'አዲስ ዋጋ አሁን ካለው ዋጋ በኋላ መጀመር አለበት።',
    ],
    'recipe' => [
        'bought_in' => 'ተገዝቶ የሚመጣ ምርት እንደመጣ ይሸጣል፤ የአዘገጃጀት መመሪያ የለውም።',
        'not_found' => 'ይህ ምርት እስካሁን የአዘገጃጀት መመሪያ የለውም።',
        'self_reference' => 'አንድ ምርት የራሱ የአዘገጃጀት መመሪያ ግብዓት ሊሆን አይችልም።',
    ],
    'validation' => [
        'decimal' => ':attribute በጽሑፍ የተጻፈ አስርዮሽ ቁጥር መሆን አለበት፤ ቢበዛ :scale አስርዮሽ ቦታዎች።',
        'positive' => ':attribute ከዜሮ በላይ መሆን አለበት።',
        'not_negative' => ':attribute አሉታዊ ሊሆን አይችልም።',
        'not_zero' => ':attribute ዜሮ ሊሆን አይችልም።',
        'below' => ':attribute ከ:limit ያነሰ መሆን አለበት።',
        'base_factor' => 'መሠረታዊ መለኪያ ፋክተሩ 1 ነው።',
    ],
];
