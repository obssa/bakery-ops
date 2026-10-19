<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Identity\Contracts\Stations;
use App\Modules\Identity\Http\Requests\ReplaceUserStationsRequest;
use App\Modules\Identity\Http\Requests\StoreUserRequest;
use App\Modules\Identity\Http\Requests\UpdateUserRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserStationAssignment;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * People (permission people.manage): users of the admin's branch. Every write is one AuditedAction; a user of
 * another branch is 404 because every lookup goes through the BranchScoped query (rule 8).
 */
final class UsersController
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ShortCodes $shortCodes,
        private readonly Stations $stations,
        private readonly CurrentBranch $branch,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(
            User::query()->with(['roles', 'permissions', 'stationAssignments'])->orderBy('name')->orderBy('id')->get(),
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $code = $request->shortCode();
        if ($code !== null) {
            $this->shortCodes->assertFormat($code);
            $this->shortCodes->assertAvailable($this->branch->id(), $code);
        }

        $user = $this->audit->run(function () use ($request): User {
            $user = new User;
            $user->fill($request->userAttributes());
            $user->branch_id = $this->branch->id();
            $user->save();
            $user->syncRoles([$request->role()]);
            $user->syncPermissions($request->permissions());

            return $user;
        }, null, 'user.create', ['role' => $request->role(), 'permissions' => $request->permissions()]);

        if ($code !== null) {
            $this->shortCodes->set($user, $code);
        }

        return response()->json((new UserResource($user->fresh() ?? $user))->resolve($request), 201);
    }

    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        $context = array_filter(['role' => $request->role(), 'permissions' => $request->permissions()], fn ($v) => $v !== null);

        $this->audit->run(function (User $u) use ($request): User {
            $u->fill($request->changes());
            $u->save();
            $role = $request->role();
            if ($role !== null) {
                $u->syncRoles([$role]);
            }
            $permissions = $request->permissions();
            if ($permissions !== null) {
                $u->syncPermissions($permissions);
            }

            return $u;
        }, $user, 'user.update', $context);

        return response()->json((new UserResource($user->fresh() ?? $user))->resolve($request));
    }

    public function resetShortCode(int $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        $code = $this->shortCodes->reset($user);

        return response()->json(['user_id' => $user->id, 'short_code' => $code]);
    }

    public function replaceStations(ReplaceUserStationsRequest $request, int $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        $assignments = $request->assignments();

        /** @var Collection<int, UserStationAssignment> $rows */
        $rows = $this->audit->run(
            fn (User $u) => $this->stations->replace($u, $assignments),
            $user,
            'user.stations.replace',
            ['assignments' => $assignments],
        );

        return response()->json([
            'user_id' => $user->id,
            'assignments' => $rows->map(fn (UserStationAssignment $a): array => [
                'station_id' => $a->station_id,
                'can_count' => $a->can_count,
                'is_overseer' => $a->is_overseer,
            ])->values()->all(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json((new UserResource(User::query()->findOrFail($id)))->resolve($request));
    }
}
