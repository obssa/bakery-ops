<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserStationAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user as People and /me show it: never the password, the short-code hash or the code itself; roles, direct
 * permission overrides and station assignments by name/id.
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $user->loadMissing(['roles', 'permissions', 'stationAssignments.station']);

        return [
            'id' => $user->id,
            'branch_id' => $user->branch_id,
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'locale' => $user->locale,
            'is_active' => $user->is_active,
            'photo_url' => $user->photo_url,
            'has_short_code' => $user->hasShortCode(),
            'roles' => $user->roles->pluck('name')->map(fn ($n): string => (string) $n)->sort()->values()->all(),
            'permissions_direct' => $user->permissions->pluck('name')->map(fn ($n): string => (string) $n)->sort()->values()->all(),
            'stations' => $user->stationAssignments
                ->sortBy('station_id')
                ->values()
                ->map(fn (UserStationAssignment $a): array => [
                    'station_id' => $a->station_id,
                    'code' => $a->station->code->value,
                    'name_en' => $a->station->name_en,
                    'name_am' => $a->station->name_am,
                    'can_count' => $a->can_count,
                    'is_overseer' => $a->is_overseer,
                ])->all(),
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The short form shown on a pad or next to an acting session: who, in which language, with which photo.
     *
     * @return array{id: int, name: string, photo_url: string|null, locale: string}
     */
    public static function brief(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'photo_url' => $user->photo_url,
            'locale' => $user->locale,
        ];
    }
}
