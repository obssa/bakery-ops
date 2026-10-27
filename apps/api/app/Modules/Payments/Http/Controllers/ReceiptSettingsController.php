<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Actions\SaveReceiptSettings;
use App\Modules\Payments\Http\Requests\UpdateReceiptSettingsRequest;
use App\Modules\Payments\Models\ReceiptSettings;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Http\JsonResponse;

/**
 * The receipt settings (phase-4 plan section 4.2 and M5): what a receipt prints at the top and the bottom.
 *
 *   GET /receipt-settings   payments.take (admin, manager, cashier): the branch's saved row, or the defaults of a branch that has saved
 *                           nothing (its name in both languages, no address, phone, TIN or footer, Amharic shown, Ethiopian dates off;
 *                           is_default true). Step 4.3a answered the defaults only; the shape is the same.
 *   PUT /receipt-settings   channels.manage (the admin), Idempotency-Key: the whole form (UpdateReceiptSettingsRequest), saved by
 *                           Actions\SaveReceiptSettings (one AuditedAction receipt_settings.update with before and after; the first save
 *                           creates the branch's one row without ever locking a missing row). The demo seed (step 4.6a) saves through it too.
 */
final class ReceiptSettingsController
{
    public function show(CurrentBranch $branch): JsonResponse
    {
        return response()->json(ReceiptSettings::view(ReceiptSettings::query()->first(), $branch->get()->name));
    }

    public function update(UpdateReceiptSettingsRequest $request, CurrentBranch $branch, SaveReceiptSettings $save): JsonResponse
    {
        return response()->json(ReceiptSettings::view($save->handle($request->settings()), $branch->get()->name));
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
