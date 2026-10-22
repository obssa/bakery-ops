<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Contracts\ActingAsSession;
use App\Modules\Identity\Contracts\Authenticate;
use App\Modules\Identity\Contracts\Stations;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Station;
use App\Shared\Shifts\OpenShifts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /login (public, throttled), GET /me, POST /logout. Bearer tokens only (ADR 0007).
 */
final class AuthController
{
    public function __construct(
        private readonly Authenticate $auth,
        private readonly ActingAsSession $sessions,
        private readonly ActorContext $actor,
        private readonly Stations $stations,
        private readonly OpenShifts $shifts,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->attempt($request->phone(), $request->password(), $request->branchId());

        return response()->json([
            'token' => $result->token,
            'token_type' => 'Bearer',
            'user' => (new UserResource($result->user))->resolve($request),
            'permissions' => $result->permissions,
            'locale' => $result->locale,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $session = $this->sessions->current();
        $deviceId = $this->actor->deviceId();

        return response()->json([
            'user' => (new UserResource($user))->resolve($request),
            'permissions' => $this->auth->permissionsOf($user),
            'locale' => $user->locale,
            'acting' => $session === null ? null : [
                'session' => ['id' => $session->id, 'started_at' => $session->started_at->toIso8601String(), 'expires_at' => $session->expires_at->toIso8601String()],
                'user' => UserResource::brief($session->user),
            ],
            'device' => $deviceId === null ? null : ['id' => $deviceId],
            'work_stations' => $this->stations->workStationsOf($user)->map(fn (Station $s): array => [
                'station_id' => $s->id,
                'code' => $s->code->value,
                'name_en' => $s->name_en,
                'name_am' => $s->name_am,
                'tracks_shifts' => $s->tracks_shifts,
                'requires_overseer' => $s->requires_overseer,
                'current_shift' => $this->shifts->currentFor($s->id),
            ])->values()->all(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->auth->logout($user);

        return response()->json(['logged_out' => true]);
    }
}
