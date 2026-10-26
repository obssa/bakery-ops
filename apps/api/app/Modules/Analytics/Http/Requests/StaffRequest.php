<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Requests;

/**
 * GET /analytics/staff (step 4.4c): the dashboard query and ?role_kind=waiter|cashier|station (every kind by default).
 */
final class StaffRequest extends DashboardRequest
{
    public const array ROLE_KINDS = ['waiter', 'cashier', 'station'];

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'role_kind' => ['sometimes', 'in:'.implode(',', self::ROLE_KINDS)],
        ];
    }

    public function roleKind(): ?string
    {
        $kind = $this->validated('role_kind');

        return is_string($kind) ? $kind : null;
    }
}
