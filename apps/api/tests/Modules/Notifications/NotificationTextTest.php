<?php

declare(strict_types=1);

use App\Modules\Notifications\Exceptions\MissingPayloadKey;
use App\Modules\Notifications\Exceptions\UnknownNotificationType;
use App\Modules\Notifications\Support\NotificationText;
use App\Modules\Notifications\Support\NotificationTypes;
use Tests\Support\NotificationEvents;

/*
 * Step 2.5a (phase-2 plan sections 5 and 7): NotificationText writes the title and the body of every type, in English and in
 * Amharic, from the event's payload alone (it never reads another module's tables). Both languages are stored in the inbox
 * row; the reader's locale picks one later. No placeholder may be left unfilled, an Amharic text is never a copy of the
 * English, and a payload that lacks what the text needs is a bug, not a half-written line.
 */

/** @return array<string, array<string, mixed>> the payload of a sample event of each type the registry knows */
function samplePayloads(): array
{
    return [
        'line_ready' => NotificationEvents::lineReady(1, 5)->payload(),
        'line_rejected' => NotificationEvents::lineRejected(1, 5)->payload(),
        'stock_out' => NotificationEvents::stockOut(1)->payload(),
        'transfer_requested' => NotificationEvents::transferRequested(1, 5)->payload(),
        'transfer_decided' => NotificationEvents::transferDecided(1, 5, 6)->payload(),
    ];
}

it('writes an English and an Amharic title and body for every type in the registry, with nothing left to fill', function () {
    $text = app(NotificationText::class);
    $samples = samplePayloads();

    expect(array_keys($samples))->toEqualCanonicalizing(NotificationTypes::all(), 'a type of the registry without a sample here has no test');
    foreach ($samples as $type => $payload) {
        $rendered = $text->render($type, $payload);
        foreach ([$rendered->title, $rendered->body] as $pair) {
            expect($pair->en)->toBeString()->not->toBeEmpty("{$type} en")
                ->and($pair->am)->toBeString()->not->toBeEmpty("{$type} am")
                ->and($pair->am)->not->toBe($pair->en, "{$type}: the Amharic is not a copy of the English")
                ->and($pair->en)->not->toMatch('/:[a-z_]+/', "{$type} en has an unfilled placeholder")
                ->and($pair->am)->not->toMatch('/:[a-z_]+/', "{$type} am has an unfilled placeholder");
        }
        expect(mb_strlen($rendered->title->en))->toBeLessThanOrEqual(160)
            ->and(mb_strlen($rendered->title->am))->toBeLessThanOrEqual(160)
            ->and(mb_strlen($rendered->body->en))->toBeLessThanOrEqual(500)
            ->and(mb_strlen($rendered->body->am))->toBeLessThanOrEqual(500);
    }
})->group('module:notifications', 'unit');

it('says a ready line with its order, table and item, each side in its own language', function () {
    $text = app(NotificationText::class);

    $ready = $text->render('line_ready', NotificationEvents::lineReady(1, 5)->payload());
    expect($ready->title->en)->toBe('Ready: Chicken sandwich')
        ->and($ready->body->en)->toBe('Order #12, table T4: Chicken sandwich is ready to serve.')
        ->and($ready->title->am)->toBe('ተዘጋጅቷል፡ የዶሮ ሳንድዊች')
        ->and($ready->body->am)->toBe('ትዕዛዝ #12፣ ጠረጴዛ T4፡ የዶሮ ሳንድዊች ለማቅረብ ዝግጁ ነው።');

    $counter = $text->render('line_ready', NotificationEvents::lineReady(1, 5, 31, ['tableCode' => null])->payload());
    expect($counter->body->en)->toBe('Order #12: Chicken sandwich is ready to serve.')
        ->and($counter->body->am)->toBe('ትዕዛዝ #12፡ የዶሮ ሳንድዊች ለማቅረብ ዝግጁ ነው።');
})->group('module:notifications', 'unit');

it('says a rejected line with the reason and the note the station wrote', function () {
    $text = app(NotificationText::class);

    $plain = $text->render('line_rejected', NotificationEvents::lineRejected(1, 5)->payload());
    expect($plain->title->en)->toBe('Sent back: Chicken sandwich')
        ->and($plain->body->en)->toBe('Order #12, table T4: Chicken sandwich was sent back. Reason: Out of stock.')
        ->and($plain->title->am)->toBe('ተመልሷል፡ የዶሮ ሳንድዊች')
        ->and($plain->body->am)->toBe('ትዕዛዝ #12፣ ጠረጴዛ T4፡ የዶሮ ሳንድዊች ተመልሷል። ምክንያት፡ ክምችቱ አልቋል።');

    $noted = $text->render('line_rejected', NotificationEvents::lineRejected(1, 5, 32, [
        'tableCode' => null, 'reason' => 'other', 'reasonEn' => 'Another reason (see the note).', 'reasonAm' => 'ሌላ ምክንያት (ማስታወሻውን ይመልከቱ)።', 'note' => 'The oven tripped',
    ])->payload());
    expect($noted->body->en)->toBe('Order #12: Chicken sandwich was sent back. Reason: Another reason (see the note). The oven tripped')
        ->and($noted->body->am)->toBe('ትዕዛዝ #12፡ የዶሮ ሳንድዊች ተመልሷል። ምክንያት፡ ሌላ ምክንያት (ማስታወሻውን ይመልከቱ)። The oven tripped');
})->group('module:notifications', 'unit');

it('says a stock-out with the item and the place in both languages', function () {
    $rendered = app(NotificationText::class)->render('stock_out', NotificationEvents::stockOut(1)->payload());

    expect($rendered->title->en)->toBe('Black forest cake is out of stock')
        ->and($rendered->body->en)->toBe('None left at Display case.')
        ->and($rendered->title->am)->toBe('ብላክ ፎረስት ኬክ አልቋል')
        ->and($rendered->body->am)->toBe('በየማሳያ ሣጥን ምንም አልቀረም።');
})->group('module:notifications', 'unit');

it('says a transfer request and its decision, writing the quantity without trailing zeros and never through a float', function () {
    $text = app(NotificationText::class);

    $asked = $text->render('transfer_requested', NotificationEvents::transferRequested(1, 5)->payload());
    expect($asked->title->en)->toBe('Stock requested: Flour')
        ->and($asked->body->en)->toBe('5 kg of Flour, from Store to Kitchen.')
        ->and($asked->title->am)->toBe('ክምችት ተጠይቋል፡ ዱቄት')
        ->and($asked->body->am)->toBe('ዱቄት፣ 5 kg፣ ከመጋዘን ወደ ወጥ ቤት።');

    $done = $text->render('transfer_decided', NotificationEvents::transferDecided(1, 5, 6)->payload());
    expect($done->title->en)->toBe('Transfer confirmed: Flour')
        ->and($done->body->en)->toBe('The 5 kg of Flour moved from Store to Kitchen.')
        ->and($done->title->am)->toBe('ዝውውር ተረጋግጧል፡ ዱቄት')
        ->and($done->body->am)->toBe('ዱቄት፣ 5 kg ከመጋዘን ወደ ወጥ ቤት ተዛውሯል።');

    $refused = $text->render('transfer_decided', NotificationEvents::transferDecided(1, 5, 6, 'rejected')->payload());
    expect($refused->title->en)->toBe('Transfer rejected: Flour')
        ->and($refused->body->en)->toBe('The request for 5 kg of Flour was rejected. Reason: The sack is for the wedding cake')
        ->and($refused->title->am)->toBe('ዝውውር ተቀባይነት አላገኘም፡ ዱቄት')
        ->and($refused->body->am)->toBe('የ5 kg ዱቄት ጥያቄ ውድቅ ተደርጓል። ምክንያት፡ The sack is for the wedding cake');

    foreach ([['5.0000', '5'], ['2.5000', '2.5'], ['1250.0000', '1250'], ['0.0500', '0.05'], ['10', '10'], ['100', '100'], ['0.1000', '0.1']] as [$stored, $written]) {
        $rendered = $text->render('transfer_requested', NotificationEvents::transferRequested(1, 5, 41, ['qty' => $stored])->payload());
        expect($rendered->body->en)->toStartWith("{$written} kg of Flour")
            ->and($rendered->body->am)->toContain("{$written} kg");
    }
})->group('module:notifications', 'unit');

it('refuses a type it does not know and a payload that lacks what the text needs', function () {
    $text = app(NotificationText::class);
    $payload = NotificationEvents::lineReady(1, 5)->payload();
    unset($payload['item_name_am']);

    expect(fn () => $text->render('count_submitted', []))->toThrow(UnknownNotificationType::class)
        ->and(fn () => $text->render('line_ready', $payload))->toThrow(MissingPayloadKey::class, 'item_name_am');
})->group('module:notifications', 'unit');

it('knows every type of the registry with a severity, channels and keys, and only the types the inbox table accepts', function () {
    $accepted = ['stock_low', 'stock_out', 'production_requested', 'production_assigned', 'batch_pending_confirmation', 'batch_done', 'transfer_requested', 'transfer_decided', 'line_ready', 'line_rejected'];

    foreach (NotificationTypes::all() as $type) {
        expect($accepted)->toContain($type)
            ->and(NotificationTypes::severity($type))->toBeIn(['info', 'warning', 'critical'])
            ->and(NotificationTypes::channels($type))->toContain('in_app')
            ->and(array_diff(NotificationTypes::channels($type), ['in_app', 'push', 'telegram', 'sms']))->toBe([]);
    }
    expect(NotificationTypes::channels('line_ready'))->toBe(['in_app'], 'in-app only (plan section 5)')
        ->and(NotificationTypes::channels('line_rejected'))->toBe(['in_app'])
        ->and(NotificationTypes::channels('stock_out'))->toBe(['in_app', 'push', 'telegram'])
        ->and(NotificationTypes::channels('transfer_requested'))->toBe(['in_app', 'push'])
        ->and(NotificationTypes::severity('stock_out'))->toBe('critical')
        ->and(NotificationTypes::has('stock_out'))->toBeTrue()
        ->and(NotificationTypes::has('nope'))->toBeFalse();
})->group('module:notifications', 'unit');

it('derives the inbox key, the delivery gate and the deep link of an event from its payload', function () {
    $out = NotificationEvents::stockOut(1, 11, 4, 21)->payload();

    expect(NotificationTypes::inboxKey('stock_out', $out))->toBe('stock_out:11:4:21', 'one inbox line per episode, so the next episode is not swallowed')
        ->and(NotificationTypes::gateKey('stock_out', $out))->toBe('stock_out:11:4', 'the gate is per item and place, as the plan says')
        ->and(NotificationTypes::gateKey('line_ready', NotificationEvents::lineReady(1, 5)->payload()))->toBeNull('only episodic alerts are gated')
        ->and(NotificationTypes::inboxKey('line_ready', NotificationEvents::lineReady(1, 5, 31)->payload()))->toBe('line_ready:31')
        ->and(NotificationTypes::inboxKey('line_rejected', NotificationEvents::lineRejected(1, 5, 32)->payload()))->toBe('line_rejected:32')
        ->and(NotificationTypes::inboxKey('transfer_decided', NotificationEvents::transferDecided(1, 5, 6)->payload()))->toBe('transfer_decided:41')
        ->and(NotificationTypes::deepLink('stock_out', $out))->toBe('/inventory?item=11&location=4')
        ->and(NotificationTypes::deepLink('line_ready', NotificationEvents::lineReady(1, 5)->payload()))->toBe('/orders/21')
        ->and(fn () => NotificationTypes::inboxKey('stock_out', ['item_id' => 1]))->toThrow(MissingPayloadKey::class);
})->group('module:notifications', 'unit');
