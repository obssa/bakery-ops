<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Models\BrandProfile;
use App\Shared\Actor\ActorContext;
use App\Shared\Files\FilesError;
use App\Shared\Files\ImageProcessor;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The only writer of brand_profiles (Phase 6 follow-up, Ramin 2026-11-10): the business name and logo the app shows. Resolved per use (it
 * reads the scoped ActorContext), never a singleton.
 *
 * saveNames(): the name pair (both or neither; neither puts the app's own name back), in one AuditedAction identity.brand.update with before
 * and after. setLogo(): the upload is decoded and checked by Shared\Files\ImageProcessor (JPEG, PNG or WebP, the pixel and byte caps, a whole
 * file; a refusal is its FilesError, files.*), drawn whole and centred on a white square of LOGO_SIZE (a transparent logo stays on white, never
 * black) and encoded as WebP where gd has it, else JPEG, with no metadata; the file is written under its sha256 before any lock, then the row
 * names it in one AuditedAction identity.brand.logo_set. removeLogo(): the row forgets it (identity.brand.logo_removed); a branch without a
 * logo writes nothing. Files are content-addressed and never deleted here: a replaced logo's file stays, so a cached page never breaks.
 *
 * Every first write creates the branch's one row without ever locking a missing row: a no-op insert (INSERT IGNORE) makes sure it exists,
 * then it is locked (traps.md, "First-row race").
 */
final class BrandProfiles
{
    /** the side of the stored square, in pixels: sharp at the 40 px mark on a 3x screen and on the sign-in page */
    public const int LOGO_SIZE = 256;

    /** the largest upload read, in bytes */
    public const int MAX_UPLOAD_BYTES = 5_242_880;

    public function __construct(
        private readonly AuditedAction $audit,
        private readonly CurrentBranch $branch,
        private readonly ActorContext $actor,
    ) {}

    public function current(): ?BrandProfile
    {
        return BrandProfile::query()->first();
    }

    public function saveNames(?string $nameEn, ?string $nameAm): BrandProfile
    {
        return $this->audit->run(function (?BrandProfile $row) use ($nameEn, $nameAm): BrandProfile {
            $row = $this->locked($row);
            $row->fill(['name_en' => $nameEn, 'name_am' => $nameAm]);
            if ($row->isDirty()) {
                $row->save();
            }

            return $row;
        }, $this->current(), 'identity.brand.update');
    }

    /**
     * @throws FilesError a refusal (nothing stored)
     */
    public function setLogo(string $bytes): BrandProfile
    {
        $processor = new ImageProcessor(maxPixels: 24_000_000, maxBytes: self::MAX_UPLOAD_BYTES);
        $image = $processor->load($bytes);
        $width = imagesx($image);
        $height = imagesy($image);
        // the whole logo, centred on the square: the longer side fills it
        $scale = self::LOGO_SIZE / max($width, $height);
        $toWidth = max(1, (int) round($width * $scale));
        $toHeight = max(1, (int) round($height * $scale));
        $fitted = $processor->resample($image, 0, 0, $width, $height, $toWidth, $toHeight);
        $square = imagecreatetruecolor(self::LOGO_SIZE, self::LOGO_SIZE);
        imagefill($square, 0, 0, (int) imagecolorallocate($square, 255, 255, 255));
        imagecopy($square, $fitted, intdiv(self::LOGO_SIZE - $toWidth, 2), intdiv(self::LOGO_SIZE - $toHeight, 2), 0, 0, $toWidth, $toHeight);
        $encoded = $processor->encodeScrubbed($square, ImageProcessor::canWebp() ? 'webp' : 'jpeg');
        unset($image, $fitted, $square);

        $sha = hash('sha256', $encoded->bytes);
        $disk = (string) config('catalog.images.disk');
        $key = 'brand/'.substr($sha, 0, 2).'/'.$sha.'.'.BrandProfile::extensionOf($encoded->mime);
        $this->store($disk, $key, $encoded->bytes);

        return $this->audit->run(function (?BrandProfile $row) use ($sha, $encoded, $disk, $key): BrandProfile {
            $row = $this->locked($row);
            $row->forceFill([
                'logo_sha256' => $sha,
                'logo_mime' => $encoded->mime,
                'logo_disk' => $disk,
                'logo_key' => $key,
                'logo_size' => self::LOGO_SIZE,
            ]);
            if ($row->isDirty()) {
                $row->save();
            }

            return $row;
        }, $this->current(), 'identity.brand.logo_set');
    }

    /**
     * @return BrandProfile|null the branch's row (null: it has none, nothing was written)
     */
    public function removeLogo(): ?BrandProfile
    {
        $current = $this->current();
        if ($current === null || $current->logo_sha256 === null) {
            return $current;
        }

        return $this->audit->run(function (BrandProfile $row): BrandProfile {
            $row = BrandProfile::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            $row->forceFill(['logo_sha256' => null, 'logo_mime' => null, 'logo_disk' => null, 'logo_key' => null, 'logo_size' => null])->save();

            return $row;
        }, $current, 'identity.brand.logo_removed');
    }

    /** The branch's row, created by a no-op insert if missing, then locked; an existing subject is refreshed under the lock (the audit reads it). */
    private function locked(?BrandProfile $subject): BrandProfile
    {
        $now = CarbonImmutable::now();
        DB::table('brand_profiles')->insertOrIgnore([
            'branch_id' => $this->branch->id(),
            'created_by_user_id' => $this->actor->requireUserId(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $locked = BrandProfile::query()->lockForUpdate()->firstOrFail();

        return $subject instanceof BrandProfile ? $subject->refresh() : $locked;
    }

    /** Written under a temporary name and moved into place, so the public URL (cached as immutable) never serves half a file. */
    private function store(string $diskName, string $key, string $bytes): void
    {
        $disk = Storage::disk($diskName);
        if ($disk->exists($key)) {
            return;
        }
        $temporary = dirname($key).'/.'.Str::random(12).'.tmp';
        $disk->put($temporary, $bytes);
        $disk->move($temporary, $key);
    }
}
