<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Controllers;

use App\Modules\Shifts\Actions\SaveCountingRules;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Http\Requests\SaveCountingRulesRequest;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Station;
use Illuminate\Http\JsonResponse;

/**
 * The counting rules (Admin > System > Counting rules, ADR 0026): shifts.manage, the admin. Another branch's station is never listed and
 * never changed (BranchScoped, and the request's exists rule names the branch).
 *
 *   GET /counting-rules  200 {data: {stations: [{id, code, name_en, name_am, opening_count, cosign, approval}], waiting: {awaiting_cosign,
 *                        pending_approval}}}: the stations that run shifts, by sort and id, and how many counts wait now (a switch does
 *                        not release them)
 *   PUT /counting-rules  200 the same, after the change; 422 validation.failed (an unknown station, one named twice, an entry that
 *                        changes nothing)
 */
final class CountingRulesController
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => self::view()]);
    }

    public function update(SaveCountingRulesRequest $input, SaveCountingRules $save): JsonResponse
    {
        $save->handle($input->changes());

        return response()->json(['data' => self::view()]);
    }

    /**
     * @return array{stations: list<array<string, mixed>>, waiting: array{awaiting_cosign: int, pending_approval: int}}
     */
    private static function view(): array
    {
        $stations = Station::query()->where('tracks_shifts', true)->orderBy('sort')->orderBy('id')->get();
        $waiting = StockCount::query()
            ->whereIn('status', [CountStatus::AwaitingCosign->value, CountStatus::PendingApproval->value])
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        return [
            'stations' => array_values($stations->map(static fn (Station $station): array => [
                'id' => $station->id,
                'code' => $station->code->value,
                'name_en' => $station->name_en,
                'name_am' => $station->name_am,
            ] + $station->countingRules())->all()),
            'waiting' => [
                'awaiting_cosign' => (int) ($waiting[CountStatus::AwaitingCosign->value] ?? 0),
                'pending_approval' => (int) ($waiting[CountStatus::PendingApproval->value] ?? 0),
            ],
        ];
    }
}
