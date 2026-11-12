<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Demo\DemoLogins;
use App\Modules\Identity\Exceptions\IdentityError;
use App\Modules\Identity\Http\Requests\UpdateDemoLoginRequest;
use App\Modules\Identity\Support\BrandProfiles;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Http\JsonResponse;

/**
 * TEMPORARY (ADR 0025, Ramin 2026-11-12): the switch of the sign-in page's one-click demo buttons, in Admin > System, so the admin turns
 * them on and off in the browser instead of editing the server's .env. Both routes need branding.manage (the admin).
 *
 *   GET /brand/demo-login  {data: {on, available, forced_off}}: on, the saved position; available, this admin's branch is the demo branch
 *                          (only its admin holds the switch; another branch's admin reads available=false, on=false); forced_off, the
 *                          server's BAKERY_DEMO_LOGIN=false keeps the buttons off whatever is saved.
 *   PUT /brand/demo-login  Idempotency-Key: {on: bool}; one AuditedAction identity.brand.demo_login with before and after on the demo
 *                          branch's brand_profiles row; answers as GET. From another branch: 409 identity.demo_login.not_demo_branch.
 *                          It saves while forced off too (the buttons follow it once the server lets them).
 */
final class DemoLoginSwitchController
{
    public function __construct(
        private readonly DemoLogins $demo,
        private readonly BrandProfiles $brand,
        private readonly CurrentBranch $branch,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->demo->state($this->branch->id())]);
    }

    public function update(UpdateDemoLoginRequest $request): JsonResponse
    {
        $branchId = $this->branch->id();
        if (! $this->demo->isDemoBranch($branchId)) {
            throw IdentityError::notDemoBranch();
        }
        $this->brand->saveDemoLogin($request->on());

        return response()->json(['data' => $this->demo->state($branchId)]);
    }
}
