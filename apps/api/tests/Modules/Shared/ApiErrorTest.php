<?php

declare(strict_types=1);

use App\Shared\Http\ApiError;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\DoorStatus;

/*
 * API errors are bilingual (rule 9): a stable message_key plus message_en and message_am, both from lang/.
 */

it('keeps the en and am message dictionaries in step', function () {
    $en = Arr::dot(require base_path('lang/en/shared.php'));
    $am = Arr::dot(require base_path('lang/am/shared.php'));

    expect(array_keys($am))->toEqualCanonicalizing(array_keys($en));
    foreach ($en as $key => $value) {
        expect($value)->toBeString()->not->toBeEmpty("en {$key} is empty")
            ->and($am[$key])->toBeString()->not->toBeEmpty("am {$key} is empty")->not->toBe($value, "am {$key} is still English");
    }
})->group('module:shared', 'invariants');

it('builds a bilingual error body from a message key', function () {
    $body = ApiError::body('idempotency.key_required', ['hint' => 'x']);

    expect($body['message_key'])->toBe('idempotency.key_required')
        ->and($body['message_en'])->toBe(trans('shared.idempotency.key_required', [], 'en'))
        ->and($body['message_am'])->toBe(trans('shared.idempotency.key_required', [], 'am'))
        ->and($body['hint'])->toBe('x');
})->group('module:shared', 'unit');

it('renders an IllegalTransition as a bilingual 409 on API routes', function () {
    Route::post('/_shared-test/transition', fn () => DoorStatus::Open->transitionTo(DoorStatus::Locked));

    $this->postJson('/_shared-test/transition')
        ->assertStatus(409)
        ->assertJsonPath('message_key', 'state_machine.illegal_transition')
        ->assertJsonPath('from', 'open')
        ->assertJsonPath('to', 'locked')
        ->assertJsonStructure(['message_key', 'message_en', 'message_am', 'machine', 'from', 'to']);
})->group('module:shared', 'feature');
