<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Models\VarianceReason;
use App\Shared\Actor\ActorContext;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * POST /variance-reasons and PATCH /variance-reasons/{reason} (phase-3 plan section 4, "Templates and reasons"; shifts.manage, the
 * admin). One AuditedAction each (variance_reason.create, variance_reason.update). A new reason goes last in the order unless a sort
 * is given; its code is chosen once and never changes (a PATCH that names a code leaves it alone); a code the branch already has is
 * 409 shifts.reason.code_taken, whether that reason is switched off or removed (the unique key is (branch, code), so a removed
 * one is not reused). Switching a reason off keeps it in the history of the lines that used it.
 */
final class SaveVarianceReason
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly CurrentBranch $branch,
        private readonly ActorContext $actor,
    ) {}

    /**
     * @param  array{code: string, name_en: string, name_am: string, requires_note?: bool, sort?: int}  $input
     *
     * @throws ShiftError shifts.reason.code_taken (409)
     */
    public function create(array $input): VarianceReason
    {
        if (VarianceReason::query()->withTrashed()->where('code', $input['code'])->exists()) {
            throw ShiftError::reasonCodeTaken($input['code']);
        }

        try {
            return $this->audit->run(function () use ($input): VarianceReason {
                $stamp = $this->actor->stamp();
                $last = VarianceReason::query()->withTrashed()->max('sort');
                $reason = new VarianceReason;
                $reason->forceFill([
                    'branch_id' => $this->branch->id(),
                    'code' => $input['code'],
                    'name_en' => $input['name_en'],
                    'name_am' => $input['name_am'],
                    'requires_note' => $input['requires_note'] ?? false,
                    'is_active' => true,
                    'sort' => $input['sort'] ?? (is_numeric($last) ? min(65535, (int) $last + 1) : 1),
                    'created_by_user_id' => $stamp['created_by_user_id'],
                    'acting_user_id' => $stamp['acting_user_id'],
                    'shift_instance_id' => $stamp['shift_instance_id'],
                    'actor_station_id' => $stamp['station_id'],
                    'device_id' => $stamp['device_id'],
                    'version' => 1,
                ])->save();

                return $reason;
            }, null, 'variance_reason.create');
        } catch (UniqueConstraintViolationException) {
            throw ShiftError::reasonCodeTaken($input['code']);
        }
    }

    /**
     * @param  array<string, mixed>  $changes  any of name_en, name_am, requires_note, is_active, sort
     */
    public function update(VarianceReason $reason, array $changes): VarianceReason
    {
        return $this->audit->run(function (VarianceReason $r) use ($changes): VarianceReason {
            $fill = [];
            foreach (['name_en', 'name_am', 'requires_note', 'is_active', 'sort'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $fill[$field] = $changes[$field];
                }
            }
            $r->forceFill($fill + ['version' => $r->version + 1])->save();

            return $r;
        }, $reason, 'variance_reason.update');
    }
}
