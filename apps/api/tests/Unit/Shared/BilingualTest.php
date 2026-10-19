<?php

declare(strict_types=1);

use App\Shared\I18n\Bilingual;

/*
 * Every user-facing string carries _en and _am (rule 9). Bilingual is the value object for that pair.
 */

it('holds both languages and picks by locale with English as the fallback', function () {
    $name = Bilingual::of('Kitchen', 'ኩሽና');

    expect($name->en)->toBe('Kitchen')
        ->and($name->am)->toBe('ኩሽና')
        ->and($name->in('am'))->toBe('ኩሽና')
        ->and($name->in('en'))->toBe('Kitchen')
        ->and($name->in('fr'))->toBe('Kitchen')
        ->and(Bilingual::of('Store', '')->in('am'))->toBe('Store');
})->group('unit', 'module:shared');

it('round-trips through arrays and JSON', function () {
    $name = Bilingual::fromArray(['en' => 'Bar', 'am' => 'ባር']);

    expect($name->toArray())->toBe(['en' => 'Bar', 'am' => 'ባር'])
        ->and(json_encode($name, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))->toBe('{"en":"Bar","am":"ባር"}')
        ->and($name->equals(Bilingual::of('Bar', 'ባር')))->toBeTrue()
        ->and($name->equals(Bilingual::of('Bar', 'Bar')))->toBeFalse();
})->group('unit', 'module:shared');

it('refuses an array without both languages', function () {
    Bilingual::fromArray(['en' => 'Only English']);
})->throws(InvalidArgumentException::class)->group('unit', 'module:shared');
