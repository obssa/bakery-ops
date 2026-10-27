<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Payments\Models\ReceiptSettings;
use App\Shared\Actor\ActorContext;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * PUT /receipt-settings (phase-4 plan section 4.2 and M5, step 4.3e): the whole form replaces the branch's one row, in one AuditedAction
 * receipt_settings.update with before and after. The row is created by the first save: a no-op insert (INSERT IGNORE / ON CONFLICT DO
 * NOTHING) makes sure it exists before it is locked, so two first saves never lock a missing row (traps.md, "First-row race") and the second
 * simply overwrites the first. Taken out of ReceiptSettingsController in step 4.6a so the demo seed saves the demo's settings the same way.
 */
final class SaveReceiptSettings
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly CurrentBranch $branch,
        private readonly ActorContext $actor,
    ) {}

    /**
     * @param  array{business_name_en: string, business_name_am: string, address_en: string|null, address_am: string|null, phone: string|null, tin: string|null, footer_en: string|null, footer_am: string|null, show_amharic: bool, ethiopian_dates: bool}  $settings
     */
    public function handle(array $settings): ReceiptSettings
    {
        $existing = ReceiptSettings::query()->first();

        return $this->audit->run(function (?ReceiptSettings $row) use ($settings): ReceiptSettings {
            $now = CarbonImmutable::now();
            DB::table('receipt_settings')->insertOrIgnore([
                'branch_id' => $this->branch->id(),
                'business_name_en' => $settings['business_name_en'],
                'business_name_am' => $settings['business_name_am'],
                'created_by_user_id' => $this->actor->requireUserId(),
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
    }
}
