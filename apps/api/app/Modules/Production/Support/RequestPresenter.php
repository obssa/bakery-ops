<?php

declare(strict_types=1);

namespace App\Modules\Production\Support;

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Catalog\Contracts\ItemSummary;
use App\Modules\Identity\Contracts\People;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Production\Models\ProductionRequest;
use Illuminate\Database\Eloquent\Collection;

/**
 * A production request as the screens read it (phase-2 plan section 4, "Resources"): the product with its base unit, the quantity,
 * the status, the station it is made at, who asked and who was named, the batch once there is one, and the version the clients
 * compare. Names of people come from Identity (a person who has since left is still named) and names of products from Catalog,
 * each in one query for the whole page, so a board of fifty requests reads as many rows as a board of two. Resolved per use.
 */
final class RequestPresenter
{
    public function __construct(
        private readonly Items $items,
        private readonly People $people,
    ) {}

    /**
     * @param  iterable<ProductionRequest>  $requests
     * @return list<array<string, mixed>>
     */
    public function many(iterable $requests): array
    {
        /** @var Collection<int, ProductionRequest> $rows */
        $rows = new Collection;
        foreach ($requests as $request) {
            $rows->push($request);
        }
        if ($rows->isEmpty()) {
            return [];
        }
        $rows->loadMissing(['station', 'batch']);

        $products = $this->items->summaries(array_values($rows->pluck('product_item_id')->map(static fn (mixed $id): int => (int) $id)->all()));
        $peopleIds = [];
        foreach ($rows as $request) {
            array_push($peopleIds, $request->requested_by_user_id, (int) $request->assigned_to_user_id);
            if ($request->batch instanceof ProductionBatch) {
                array_push($peopleIds, (int) $request->batch->finished_by_user_id, (int) $request->batch->confirmed_by_user_id);
            }
        }
        $names = $this->people->names(array_values(array_filter(array_unique($peopleIds), static fn (int $id): bool => $id > 0)));

        $out = [];
        foreach ($rows as $request) {
            $out[] = $this->row($request, $products, $names);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function one(ProductionRequest $request): array
    {
        return $this->many([$request])[0];
    }

    /**
     * A batch on its own (the answer of Done, Confirm and Send back): the batch as the request resource carries it, plus the request
     * it belongs to in short, so a screen moves the card without reading the board again.
     *
     * @return array<string, mixed>
     */
    public function batch(ProductionBatch $batch): array
    {
        $request = $batch->request;
        $names = $this->people->names(array_values(array_filter(
            array_unique([(int) $batch->finished_by_user_id, (int) $batch->confirmed_by_user_id]),
            static fn (int $id): bool => $id > 0,
        )));
        $person = static fn (?int $id): ?array => $id === null ? null : ['id' => $id, 'name' => $names[$id] ?? ''];

        return $this->batchRow($batch, $person) + [
            'request_id' => $request->id,
            'request' => ['id' => $request->id, 'status' => $request->status->value, 'version' => $request->version],
        ];
    }

    /**
     * @param  array<int, ItemSummary>  $products
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function row(ProductionRequest $request, array $products, array $names): array
    {
        $product = $products[$request->product_item_id] ?? null;
        $station = $request->station;
        $person = static fn (?int $id): ?array => $id === null ? null : ['id' => $id, 'name' => $names[$id] ?? ''];

        return [
            'id' => $request->id,
            'client_uuid' => $request->client_uuid,
            'product' => [
                'id' => $request->product_item_id,
                'name_en' => $product->nameEn ?? '',
                'name_am' => $product->nameAm ?? '',
                'unit' => $product->unit ?? '',
            ],
            'requested_qty' => $request->requested_qty->toString(),
            'due_at' => $request->due_at?->toIso8601String(),
            'priority' => $request->priority->value,
            'reason' => $request->reason->value,
            'status' => $request->status->value,
            'station' => [
                'id' => $station->id,
                'code' => $station->code->value,
                'name_en' => $station->name_en,
                'name_am' => $station->name_am,
                'requires_overseer' => $station->requires_overseer,
            ],
            'requested_by' => $person($request->requested_by_user_id),
            'assigned_to' => $person($request->assigned_to_user_id),
            'note' => $request->note,
            'accepted_at' => $request->accepted_at?->toIso8601String(),
            'done_at' => $request->done_at?->toIso8601String(),
            'cancelled_at' => $request->cancelled_at?->toIso8601String(),
            'cancel_reason' => $request->cancel_reason,
            'batch' => $request->batch instanceof ProductionBatch ? $this->batchRow($request->batch, $person) : null,
            'created_at' => $request->created_at?->toIso8601String(),
            'version' => $request->version,
        ];
    }

    /**
     * @param  callable(?int): ?array{id: int, name: string}  $person
     * @return array<string, mixed>
     */
    private function batchRow(ProductionBatch $batch, callable $person): array
    {
        return [
            'id' => $batch->id,
            'client_uuid' => $batch->client_uuid,
            'status' => $batch->status->value,
            'recipe_version' => $batch->recipe_version,
            'planned_qty' => $batch->planned_qty->toString(),
            'produced_qty' => $batch->produced_qty?->toString(),
            'waste_qty' => $batch->waste_qty?->toString(),
            'started_at' => $batch->started_at->toIso8601String(),
            'finished_by' => $person($batch->finished_by_user_id),
            'finished_at' => $batch->finished_at?->toIso8601String(),
            'confirmed_by' => $person($batch->confirmed_by_user_id),
            'confirmed_at' => $batch->confirmed_at?->toIso8601String(),
            'send_back_note' => $batch->send_back_note,
            'send_back_count' => $batch->send_back_count,
            'version' => $batch->version,
        ];
    }
}
