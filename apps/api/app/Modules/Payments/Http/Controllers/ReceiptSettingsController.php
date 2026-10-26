<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Payments\Http\Requests\UpdateReceiptSettingsRequest;
use App\Modules\Payments\Models\ReceiptSettings;
use App\Shared\Actor\ActorContext;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The receipt settings (phase-4 plan section 4.2 and M5): what a receipt prints at the top and the bottom.
 *
 *   GET /receipt-settings   payments.take (admin, manager, cashier): the branch's saved row, or the defaults of a branch that has saved
 *                           nothing (its name in both languages, no address, phone, TIN or footer, Amharic shown, Ethiopian dates off;
 *                           is_default true). Step 4.3a answered the defaults only; the shape is the same.
 *   PUT /receipt-settings   channels.manage (the admin), Idempotency-Key: the whole form (UpdateReceiptSettingsRequest), one AuditedAction
 *                           receipt_settings.update with before and after. The branch's one row is created by the first save: a no-op
 *                           insert (INSERT IGNORE / ON CONFLICT DO NOTHING) makes sure it exists before it is locked, so two first saves
 *                           never lock a missing row (traps.md, "First-row race") and the second simply overwrites the first.
 */
final class ReceiptSettingsController
{
    public function show(CurrentBranch $branch): JsonResponse
    {
        return response()->json(ReceiptSettings::view(ReceiptSettings::query()->first(), $branch->get()->name));
    }

    public function update(UpdateReceiptSettingsRequest $request, CurrentBranch $branch, ActorContext $actor, AuditedAction $audit): JsonResponse
    {
        $settings = $request->settings();
        $existing = ReceiptSettings::query()->first();

        $row = $audit->run(function (?ReceiptSettings $row) use ($settings, $branch, $actor): ReceiptSettings {
            $now = CarbonImmutable::now();
            DB::table('receipt_settings')->insertOrIgnore([
                'branch_id' => $branch->id(),
                'business_name_en' => $settings['business_name_en'],
                'business_name_am' => $settings['business_name_am'],
                'created_by_user_id' => $actor->requireUserId(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $locked = ReceiptSettings::query()->lockForUpdate()->firstOrFail();
            // the audit's after is read from the subject it was given, so an existing row is the same instance, refreshed under the lock
            $row = $row instanceof ReceiptSettings ? $row->refresh() : $locked;
            $row->fill($settings);
            if ($row->isDirty()) {
                $row->save();
            }

            return $row;
        }, $existing, 'receipt_settings.update');

        return response()->json(ReceiptSettings::view($row, $branch->get()->name));
    }

    /**
     * The defaults a branch that has saved nothing reads (kept for the callers of step 4.3a).
     *
     * @return array{business_name_en: string, business_name_am: string, address_en: string|null, address_am: string|null, phone: string|null, tin: string|null, footer_en: string|null, footer_am: string|null, show_amharic: bool, ethiopian_dates: bool, is_default: bool}
     */
    public static function defaults(string $branchName): array
    {
        return ReceiptSettings::defaults($branchName);
    }
}
