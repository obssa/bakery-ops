<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The business name and logo the app shows for a branch (Phase 6 follow-up, Ramin 2026-11-10): one row per branch, written only by
 * Support\BrandProfiles (PUT /brand, POST and DELETE /brand/logo) and read by everyone signed in through GET /me (view()). A branch that has
 * saved nothing has no row, and the app shows its own name and mark. Changed in place, never deleted. The repository never holds a
 * bakery's name or logo: they live in this table and on the media disk of the live site.
 *
 * @property int $id
 * @property int $branch_id
 * @property string|null $name_en
 * @property string|null $name_am
 * @property string|null $logo_sha256
 * @property string|null $logo_mime
 * @property string|null $logo_disk
 * @property string|null $logo_key
 * @property int|null $logo_size
 * @property int $created_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class BrandProfile extends Model
{
    use BranchScoped;

    protected $table = 'brand_profiles';

    /** @var list<string> */
    protected $fillable = ['name_en', 'name_am'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'logo_size' => 'integer',
            'created_by_user_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** jpg or webp: the extension of the logo's file and its URL. */
    public static function extensionOf(string $mime): string
    {
        return $mime === 'image/webp' ? 'webp' : 'jpg';
    }

    /**
     * The brand as GET /me and the /brand writes answer it: the saved name pair (null when none: the app shows its own), the logo's public
     * URL and size (null when none) and is_default, true while the branch shows the app's own name and mark (no row, or a row with neither).
     *
     * @return array{name_en: string|null, name_am: string|null, logo: array{url: string, size: int}|null, is_default: bool}
     */
    public static function view(?self $row): array
    {
        if (! $row instanceof self) {
            return ['name_en' => null, 'name_am' => null, 'logo' => null, 'is_default' => true];
        }
        $logo = $row->logo_sha256 === null || $row->logo_mime === null ? null : [
            'url' => '/api/v1/media/brand/'.$row->logo_sha256.'.'.self::extensionOf($row->logo_mime),
            'size' => (int) $row->logo_size,
        ];

        return [
            'name_en' => $row->name_en,
            'name_am' => $row->name_am,
            'logo' => $logo,
            'is_default' => $row->name_en === null && $logo === null,
        ];
    }
}
