<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Contracts\ActingAsSession;
use App\Modules\Identity\Contracts\Authenticate;
use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Identity\Exceptions\IdentityError;
use App\Modules\Identity\Http\Middleware\ResolveActor;
use App\Modules\Identity\Http\Requests\VerifyShortCodeRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Http\JsonResponse;

/**
 * POST /short-code/verify (public, throttled): the shared pad's lock screen. The pad must say which device it is
 * (X-Device-Id); an enrolled device gives the branch, otherwise branch_id is required until Phase 5. The right code
 * starts a 10-minute acting session and returns the actor. When the pad is also logged in (bearer token), the
 * audit row names that account as actor and the verified person as acting user.
 */
final class ShortCodeController
{
    public function __construct(
        private readonly ShortCodes $shortCodes,
        private readonly ActingAsSession $sessions,
        private readonly Authenticate $auth,
        private readonly AuditedAction $audit,
        private readonly ActorContext $actor,
        private readonly CurrentBranch $branch,
    ) {}

    public function verify(VerifyShortCodeRequest $request): JsonResponse
    {
        $fingerprint = trim((string) $request->header(ResolveActor::DEVICE_HEADER, ''));
        if ($fingerprint === '') {
            throw IdentityError::deviceRequired();
        }
        $device = ResolveActor::deviceFor($request, null);
        $branchId = $device !== null ? $device->branch_id : ($request->branchId() ?? throw IdentityError::branchRequired());

        $user = $this->shortCodes->verify($branchId, $request->code(), $fingerprint, $request->userId());

        $this->branch->set(Branch::query()->findOrFail($branchId));
        $account = $request->user('sanctum');
        if ($account instanceof User && $account->branch_id === $branchId && $account->is_active) {
            $this->actor->authenticate($account->id)->actingAs($user->id);
        } else {
            $this->actor->authenticate($user->id);
        }
        $this->actor->onDevice($device?->id);

        $session = $this->sessions->start($user, $device);
        $this->audit->recordEvent('short_code.verified', $user->getMorphClass(), $user->id, [
            'acting_session_id' => $session->id,
            'device_id' => $device?->id,
        ]);

        return response()->json([
            'user' => UserResource::brief($user),
            'permissions' => $this->auth->permissionsOf($user),
            'acting_session' => [
                'id' => $session->id,
                'started_at' => $session->started_at->toIso8601String(),
                'expires_at' => $session->expires_at->toIso8601String(),
            ],
        ]);
    }
}
