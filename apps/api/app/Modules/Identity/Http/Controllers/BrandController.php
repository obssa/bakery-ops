<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\StoreBrandLogoRequest;
use App\Modules\Identity\Http\Requests\UpdateBrandRequest;
use App\Modules\Identity\Models\BrandProfile;
use App\Modules\Identity\Support\BrandProfiles;
use App\Shared\Tenancy\BranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The business name and logo the app shows (Phase 6 follow-up, Ramin 2026-11-10). Every signed-in person reads them in GET /me (`brand`).
 *
 *   PUT    /brand        branding.manage (the admin), Idempotency-Key: {name_en, name_am}, both or neither; answers {data: brand}
 *   POST   /brand/logo   branding.manage, Idempotency-Key, multipart `logo`: stored as a 256 px square; answers {data: brand}
 *   DELETE /brand/logo   branding.manage, Idempotency-Key: the app's own mark again; answers {data: brand} (nothing written without a logo)
 *   GET    /media/brand/{sha}.{ext}   public: the logo's file. An <img> on the sign-in page sends no bearer token, and a logo is not secret.
 *                        The URL names the file's sha256, so it cannot be guessed and changes with the logo, which is why the answer may be
 *                        cached for good (as the product photos are, Catalog's MediaController). One indexed select (any branch), a file read;
 *                        404 when no row names the sha with that extension or the file is missing.
 */
final class BrandController
{
    public function __construct(private readonly BrandProfiles $brand) {}

    public function update(UpdateBrandRequest $request): JsonResponse
    {
        [$nameEn, $nameAm] = $request->names();

        return response()->json(['data' => BrandProfile::view($this->brand->saveNames($nameEn, $nameAm))]);
    }

    public function storeLogo(StoreBrandLogoRequest $request): JsonResponse
    {
        return response()->json(['data' => BrandProfile::view($this->brand->setLogo($request->logoBytes()))]);
    }

    public function destroyLogo(): JsonResponse
    {
        return response()->json(['data' => BrandProfile::view($this->brand->removeLogo())]);
    }

    public function media(Request $request, string $sha, string $ext): Response|StreamedResponse
    {
        $row = BrandProfile::query()->withoutGlobalScope(BranchScope::class)->toBase()
            ->where('logo_sha256', $sha)
            ->first(['logo_mime', 'logo_disk', 'logo_key']);
        if ($row === null || BrandProfile::extensionOf((string) $row->logo_mime) !== $ext) {
            abort(404);
        }

        $etag = '"'.$sha.'"';
        $headers = ['Cache-Control' => 'public, max-age=31536000, immutable', 'ETag' => $etag, 'X-Content-Type-Options' => 'nosniff'];
        foreach (explode(',', (string) $request->headers->get('If-None-Match', '')) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || (str_starts_with($candidate, 'W/') ? substr($candidate, 2) : $candidate) === $etag) {
                return new Response('', 304, $headers);
            }
        }

        $disk = Storage::disk((string) $row->logo_disk);
        try {
            $size = $disk->size((string) $row->logo_key);
            $handle = $disk->readStream((string) $row->logo_key);
        } catch (Throwable) {
            abort(404);
        }
        if (! is_resource($handle)) {
            abort(404);
        }

        return new StreamedResponse(static function () use ($handle): void {
            fpassthru($handle);
            fclose($handle);
        }, 200, $headers + ['Content-Type' => (string) $row->logo_mime, 'Content-Length' => (string) $size]);
    }
}
