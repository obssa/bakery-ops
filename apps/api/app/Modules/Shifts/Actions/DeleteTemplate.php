<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Shifts\Models\ShiftTemplate;

/**
 * DELETE /shift-templates/{template} (phase-3 plan section 4): a soft delete, one AuditedAction (shift_template.delete). The
 * instances already made from the template keep pointing at it (their relation reads soft-deleted templates) and keep their
 * windows; no new instance can be made from it.
 */
final class DeleteTemplate
{
    public function __construct(private readonly AuditedAction $audit) {}

    public function handle(ShiftTemplate $template): ShiftTemplate
    {
        return $this->audit->run(function (ShiftTemplate $t): ShiftTemplate {
            $t->forceFill(['version' => $t->version + 1])->save();
            $t->delete();

            return $t;
        }, $template, 'shift_template.delete');
    }
}
