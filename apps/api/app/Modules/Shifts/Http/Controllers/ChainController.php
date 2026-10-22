<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Controllers;

use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Shifts\Contracts\HashChain;
use App\Modules\Shifts\Http\Requests\ListChainRequest;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The hash chain of a place (phase-3 plan section 4, "Chain (3.4)"; ADR 0012). Both reads need shifts.replay (admin, manager); a place
 * of another branch is 404 (rule 8).
 *
 *   GET /locations/{location}/chain          200 {data: [{count_id, chain_seq, type, shift_id, shift_date, locked_at, previous_hash, payload_hash,
 *                                            superseded_by_id}], head: {chain_seq, payload_hash} or null}, newest first; ?limit= (default 50,
 *                                            at most 200), ?before_seq= pages back; the head is the newest link whatever the page
 *   GET /locations/{location}/chain/verify   200 {ok, checked, head_hash, failures: [{chain_seq, count_id, kind}]}, kind gap | previous_mismatch |
 *                                            hash_mismatch | payload_mismatch (HashChain::verify)
 */
final class ChainController
{
    public function index(ListChainRequest $input, int $location, Locations $locations): JsonResponse
    {
        $this->assertPlace($location, $locations);

        $links = StockCount::query()
            ->where('location_id', $location)->whereNotNull('chain_seq')
            ->when($input->beforeSeq() !== null, fn ($q) => $q->where('chain_seq', '<', $input->beforeSeq()))
            ->orderByDesc('chain_seq')->limit($input->limit())->get();
        $shifts = ShiftInstance::query()->whereKey($links->pluck('shift_instance_id')->unique()->all())->get()->keyBy('id');
        $head = StockCount::query()->where('location_id', $location)->whereNotNull('chain_seq')->orderByDesc('chain_seq')->first();

        return response()->json([
            'data' => $links->map(static fn (StockCount $link): array => [
                'count_id' => $link->id,
                'chain_seq' => (int) $link->chain_seq,
                'type' => $link->type->value,
                'shift_id' => $link->shift_instance_id,
                'shift_date' => $shifts[$link->shift_instance_id]?->date->toDateString(),
                'locked_at' => $link->locked_at?->format('Y-m-d\TH:i:s.uP'),
                'previous_hash' => $link->previous_hash,
                'payload_hash' => $link->payload_hash,
                'superseded_by_id' => $link->superseded_by_id,
            ])->values()->all(),
            'head' => $head === null ? null : ['chain_seq' => (int) $head->chain_seq, 'payload_hash' => $head->payload_hash],
        ]);
    }

    public function verify(Request $input, int $location, Locations $locations, HashChain $chain): JsonResponse
    {
        $this->assertPlace($location, $locations);

        return response()->json($chain->verify($location)->toArray());
    }

    private function assertPlace(int $location, Locations $locations): void
    {
        if (! $locations->has($location)) {
            throw new NotFoundHttpException;
        }
    }
}
