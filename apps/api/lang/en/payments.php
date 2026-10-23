<?php

declare(strict_types=1);

/*
 * English messages for App\Modules\Payments (message_key = payments.<group>.<name>). Every key has an Amharic twin in
 * lang/am/payments.php (rule 9); tests/Modules/Payments/PaymentsLangTest.php keeps them in step.
 */
return [
    'channel' => [
        'unknown' => 'This payment method does not exist.',
        'disabled' => 'This payment method is switched off.',
        'photo_required' => 'This payment method needs a photo of the receipt, which cannot be taken yet. Take cash for now.',
        'reference_required' => 'Type the reference number of the transfer.',
    ],
    'amount' => [
        'mismatch' => 'The amount must be the whole balance of the order. Part payments and overpayments are not accepted.',
    ],
    'tendered' => [
        'too_low' => 'The cash handed over is less than the amount to pay.',
    ],
    'order' => [
        'not_payable' => 'This order cannot be paid: it must have been sent and not be cancelled, voided or closed.',
        'settled' => 'This order is already paid. It closes when its last item is served.',
    ],
    'shift' => [
        'required' => 'Start your shift before taking cash. A payment needs a shift that is open.',
    ],
    'client_uuid' => [
        'reused' => 'This payment id was already used for another order.',
    ],
];
