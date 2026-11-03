<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Models\ItemImage;
use App\Shared\Tenancy\BranchScope;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * GET /api/v1/media/items/{sha}/{rendition}.{ext} (step 6.1a, phase-6 plan 4.2, ADR 0022): a product photo's rendition, public. No
 * bearer token, no actor, no session and no cookie: an <img> on a pad sends none, and product photos are not secret. The URL holds the
 * sha256 of the photo, so it cannot be guessed and it changes whenever the photo changes; that is why the answer may be cached for good
 * (Cache-Control: public, max-age=31536000, immutable) by the browser, Cloudflare and the pads' service worker (step 6.3a).
 *
 * One indexed select (sha256, deleted_at IS NULL, LIMIT 1, any branch: two items may share a photo, and it is served while any live row
 * names it) and a file read. 404 when no live row has the sha, when the extension is not the row's format, or when the file is missing; a
 * sha, rendition or extension outside the route's pattern never reaches here (404 too). If-None-Match is compared weakly (Cloudflare turns
 * a strong ETag weak when it compresses), so a matching one is a 304.
 */
final class MediaController
{
    public const CACHE_CONTROL = 'public, max-age=31536000, immutable';

    public function show(Request $request, string $sha, string $rendition, string $ext): Response|StreamedResponse
    {
        $row = ItemImage::query()->withoutGlobalScope(BranchScope::class)->toBase()
            ->where('sha256', $sha)
            ->first(['mime', 'disk', 'key_prefix']);
        if ($row === null || ItemImage::extensionOf((string) $row->mime) !== $ext) {
            abort(404);
        }

        $etag = '"'.$sha.'-'.$rendition.'"';
        $headers = [
            'Cache-Control' => self::CACHE_CONTROL,
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ];
        if (self::matches((string) $request->headers->get('If-None-Match', ''), $etag)) {
            return new Response('', 304, $headers);
        }

        $disk = Storage::disk((string) $row->disk);
        $path = $row->key_prefix.'/'.$rendition.'.'.$ext;
        try {
            $size = $disk->size($path);
            $handle = $disk->readStream($path);
        } catch (Throwable) {
            abort(404);
        }
        if (! is_resource($handle)) {
            abort(404);
        }

        return new StreamedResponse(static function () use ($handle): void {
            fpassthru($handle);
            fclose($handle);
        }, 200, $headers + [
            'Content-Type' => (string) $row->mime,
            'Content-Length' => (string) $size,
        ]);
    }

    /** Weak comparison (RFC 9110 13.1.2): W/ is ignored on either side; * matches any representation. */
    private static function matches(string $ifNoneMatch, string $etag): bool
    {
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || (str_starts_with($candidate, 'W/') ? substr($candidate, 2) : $candidate) === $etag) {
                return true;
            }
        }

        return false;
    }
}
