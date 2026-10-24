<?php

declare(strict_types=1);

namespace App\Shared\Files\Http;

use App\Shared\Files\Files;
use App\Shared\Files\StoredFile;
use App\Shared\Tenancy\BranchScope;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/v1/files/{file}: shows a stored image. No bearer token (an <img> cannot send one): the `signed` middleware is the whole
 * protection, and the signature names this file's id and an expiry, so a changed id, expiry or signature is a 403. The branch scope is
 * lifted on purpose: there is no signed-in branch, the signature stands for the grant. A deleted file or a missing object is a 404.
 * Step 4.2b adds the API siblings (upload, fresh URL, delete) around this route.
 */
final class ServeFile
{
    public function __invoke(Request $request, Files $files, int $file): StreamedResponse
    {
        $row = StoredFile::query()->withoutGlobalScope(BranchScope::class)->whereKey($file)->first();
        if ($row === null || ! $files->exists($row)) {
            abort(404);
        }
        $handle = $files->stream($row);

        return new StreamedResponse(static function () use ($handle): void {
            fpassthru($handle);
            fclose($handle);
        }, 200, [
            'Content-Type' => $row->mime,
            'Content-Length' => (string) $row->bytes,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
