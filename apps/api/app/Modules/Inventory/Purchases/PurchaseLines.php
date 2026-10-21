<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchases;

use App\Modules\Inventory\Models\Purchase;
use App\Modules\Inventory\Models\PurchaseLine;
use Illuminate\Validation\ValidationException;

/**
 * Writes the lines of a draft purchase inside the caller's AuditedAction (SaveDraft, ReplaceLines):
 *   - sync(): the draft's live lines become the priced list, matched by line client UUID: a line sent again keeps its row and
 *     takes the new item, quantity, unit and cost; a line left out is soft-deleted (rule 4: nothing is removed); a new
 *     one is added. The purchase total is kept in step (the sum of the line totals);
 *   - assertUnused(): a line client UUID is used once per branch (ADR 0003), also by lines removed from a draft: 422
 *     validation.failed on the line.
 * Nothing reaches the ledger or the costs here; that is ConfirmPurchase.
 */
final class PurchaseLines
{
    /**
     * @param  list<LineInput>  $inputs
     * @param  list<string>  $mine  client UUIDs this purchase may reuse: its own live lines
     *
     * @throws ValidationException
     */
    public function assertUnused(array $inputs, array $mine = []): void
    {
        $asked = array_values(array_diff(array_map(static fn (LineInput $l): string => $l->clientUuid, $inputs), $mine));
        if ($asked === []) {
            return;
        }
        $used = array_map(static fn (mixed $uuid): string => strtolower((string) $uuid), PurchaseLine::query()->withTrashed()->whereIn('client_uuid', $asked)->pluck('client_uuid')->all());
        $errors = [];
        foreach ($inputs as $input) {
            if (in_array($input->clientUuid, $used, true)) {
                $field = "lines.{$input->index}.client_uuid";
                $message = trans('validation.unique', ['attribute' => $field]);
                $errors[$field] = [is_string($message) ? $message : "The {$field} has already been taken."];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  list<PricedLine>  $priced
     */
    public function sync(Purchase $purchase, array $priced): void
    {
        $existing = [];
        foreach (PurchaseLine::query()->where('purchase_id', $purchase->id)->orderBy('id')->get() as $line) {
            $existing[strtolower($line->client_uuid)] = $line;
        }

        $kept = [];
        foreach ($priced as $line) {
            $input = $line->input;
            $row = $existing[$input->clientUuid] ?? new PurchaseLine;
            if (! $row->exists) {
                $row->forceFill(['branch_id' => $purchase->branch_id, 'purchase_id' => $purchase->id, 'client_uuid' => $input->clientUuid]);
            }
            $row->forceFill([
                'item_id' => $input->itemId,
                'qty' => $input->qty,
                'unit_id' => $input->unitId,
                'qty_base' => $line->qtyBase,
                'unit_cost' => $input->unitCost,
                'unit_cost_base' => $line->unitCostBase,
                'line_total' => $line->lineTotal,
            ]);
            if ($row->isDirty()) {
                $row->save();
            }
            $kept[$input->clientUuid] = true;
        }
        foreach ($existing as $uuid => $row) {
            if (! isset($kept[$uuid])) {
                $row->delete();
            }
        }

        $purchase->forceFill(['total' => LineResolver::total($priced, $purchase->currency)]);
    }
}
