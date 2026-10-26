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
        'photo_required' => 'This payment method needs a photo of the receipt. Take the photo, then pay.',
        'reference_required' => 'Type the reference number of the transfer.',
        'code_taken' => 'Another payment method of this branch already has this code (a removed one keeps its code). Choose a different code.',
        'pattern_invalid' => 'The reference pattern is not accepted. Use at most 120 characters: letters, digits, [classes], \d, \w, \s, groups (...), a choice | and counts such as {10} up to 64. A group that repeats may not hold another repeat or a choice.',
        'cash_locked' => 'Cash cannot be switched off, removed, or made to ask for a photo, a reference or a reference pattern: the drawer count is built on it.',
        'order_incomplete' => 'Give every payment method of the branch exactly once, in the new order.',
    ],
    'photo' => [
        'invalid' => 'This receipt photo cannot be used: it is not found, already on another payment, or too old. Take a new photo.',
    ],
    'reference' => [
        'invalid' => 'This reference number does not look like the references of this payment method. Check it against the receipt.',
    ],
    'amount' => [
        'mismatch' => 'The amount is more than the balance of the order. Take at most what is left to pay.',
    ],
    'batch' => [
        'sum_mismatch' => 'The payments must add up to exactly the balance of the order.',
        'row_invalid' => 'One of the payments cannot be taken, so none was. Correct that payment and try again.',
    ],
    'warning' => [
        'reference_duplicate' => 'This reference number was already used for another payment by this method. Check it before the customer leaves.',
        'receipt_duplicate' => 'This receipt photo is the same as one already on another payment. Check it before the customer leaves.',
    ],
    'tendered' => [
        'too_low' => 'The cash handed over is less than the amount to pay.',
    ],
    'order' => [
        'not_payable' => 'This order cannot be paid: it must have been sent and not be cancelled, voided or closed.',
        'settled' => 'This order is already paid. It closes when its last item is served.',
    ],
    'shift' => [
        'required' => 'Start your shift before taking a payment. A payment needs a shift that is open.',
    ],
    'client_uuid' => [
        'reused' => 'This payment id was already used for another order.',
    ],
    // step 4.3c: disputing and voiding a payment
    'dispute' => [
        'reason_required' => 'Say why the payment is disputed, in 3 to 255 characters.',
    ],
    'void' => [
        'order_closed' => 'This order is paid and closed, so its payments cannot be voided. Refund the order instead.',
        'refunded' => 'Money from this payment was already given back, so it cannot be voided.',
        'reason_required' => 'Say why the payment is voided, in at most 255 characters.',
        'pin_required' => 'A manager must type their PIN (4 to 6 digits) to approve the void.',
    ],
    // step 4.3e: receipts, the reconciliation and the export
    'receipt' => [
        'unpaid' => 'This order has no receipt: it is not paid in full. Ask for a pro-forma instead, or take the rest of the payment first.',
        'link_invalid' => 'This receipt link is not valid, or it has expired. Ask the bakery for a new one.',
    ],
    'reconciliation' => [
        'range_too_large' => 'The reconciliation reads at most 62 days at once. Choose a shorter range.',
    ],
    'export' => [
        'too_large' => 'Too many payments for one file. Choose fewer days, a channel or a status.',
    ],
];
