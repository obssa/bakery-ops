<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Http\JsonResponse;

/**
 * GET /receipt-settings (phase-4 plan section 4.2; payments.take): what a receipt prints at the top and the bottom. A placeholder in
 * step 4.3a: the receipt_settings table (M5), the PUT (channels.manage) and the receipts themselves are step 4.3e, so every branch
 * answers the defaults the plan gives a branch that has saved nothing: its name in both languages, no address, phone, TIN or footer,
 * Amharic shown, Ethiopian dates off, and is_default true. The shape is the one 4.3e keeps.
 */
final class ReceiptSettingsController
{
    public function show(CurrentBranch $branch): JsonResponse
    {
        return response()->json(self::defaults($branch->get()->name));
    }

    /**
     * @return array{business_name_en: string, business_name_am: string, address_en: string|null, address_am: string|null, phone: string|null, tin: string|null, footer_en: string|null, footer_am: string|null, show_amharic: bool, ethiopian_dates: bool, is_default: bool}
     */
    public static function defaults(string $branchName): array
    {
        return [
            'business_name_en' => $branchName,
            'business_name_am' => $branchName,
            'address_en' => null,
            'address_am' => null,
            'phone' => null,
            'tin' => null,
            'footer_en' => null,
            'footer_am' => null,
            'show_amharic' => true,
            'ethiopian_dates' => false,
            'is_default' => true,
        ];
    }
}
