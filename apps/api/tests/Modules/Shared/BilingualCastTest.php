<?php

declare(strict_types=1);

use App\Shared\Enums\StationCode;
use App\Shared\I18n\Bilingual;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;

/*
 * The Bilingual cast maps one model attribute (`name`) onto the `_en` / `_am` column pair (rule 9).
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
});

it('writes a Bilingual value into the _en and _am columns', function () {
    $station = Station::query()->create(['code' => StationCode::CakeRoom, 'name' => Bilingual::of('Cake room', 'የኬክ ክፍል')]);

    $this->assertDatabaseHas('stations', ['id' => $station->id, 'name_en' => 'Cake room', 'name_am' => 'የኬክ ክፍል']);
})->group('module:shared', 'feature');

it('reads the pair back as one Bilingual value', function () {
    $id = Station::query()->create(['code' => StationCode::Bakery, 'name_en' => 'Bakery', 'name_am' => 'ዳቦ ቤት'])->id;

    $station = Station::query()->findOrFail($id);

    expect($station->name)->toBeInstanceOf(Bilingual::class)
        ->and($station->name->en)->toBe('Bakery')
        ->and($station->name->in('am'))->toBe('ዳቦ ቤት')
        ->and($station->toArray())->toMatchArray(['name_en' => 'Bakery', 'name_am' => 'ዳቦ ቤት']);
})->group('module:shared', 'feature');

it('accepts an en/am array on write, as request payloads arrive', function () {
    $station = Station::query()->create(['code' => StationCode::Store, 'name' => ['en' => 'Store', 'am' => 'መጋዘን']]);

    expect($station->fresh()?->name->am)->toBe('መጋዘን');
})->group('module:shared', 'feature');

it('casts the station code to the StationCode enum', function () {
    $station = Station::query()->create(['code' => 'till', 'name' => Bilingual::of('Till', 'ካሻ')]);

    expect($station->fresh()?->code)->toBe(StationCode::Till);
})->group('module:shared', 'feature');
