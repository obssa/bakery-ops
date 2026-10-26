<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Shared\Actor\ActorContext;
use App\Shared\Http\ApiError;
use App\Shared\Models\IdempotencyKey;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Rule 7 / ADR 0003. Every POST/PUT/PATCH/DELETE needs an Idempotency-Key. Scope is (branch, user, key):
 *   - a lock (Redis SET NX in production, the cache store's lock in general) guards the key while the first
 *     request runs; a duplicate arriving meanwhile gets 409 and retries;
 *   - the same key with the same request fingerprint replays the stored response (status + body) and marks it
 *     Idempotency-Replayed: true; the handler never runs twice;
 *   - the same key with a different fingerprint is 422;
 *   - 5xx responses are not stored, so a transient failure can be retried with the same key;
 *   - snapshots expire after config('bakery.idempotency.ttl_hours').
 * Secrets (step 4.3e): the fields named in SECRET_FIELDS (a PIN, a short code, a password) are left out of the fingerprint at any depth, so
 * nobody who reads idempotency_keys can find a 4 to 6 digit PIN by hashing the rest of the body with every candidate. The same key with every
 * other field equal is therefore the same request whatever secret came with it: a success is replayed (the PIN is not checked again), and a
 * refusal of a request that carried a secret is not stored at all (a wrong PIN wrote nothing, and the same key with the right PIN must be
 * checked again). An answer that itself carries a secret (the short-code reset hands the new code back) is stored encrypted with the app key.
 * Runs after authentication: it needs a resolved CurrentBranch and an authenticated ActorContext.
 */
final class Idempotency
{
    public const string HEADER = 'Idempotency-Key';

    public const string REPLAYED_HEADER = 'Idempotency-Replayed';

    private const array WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * The request fields that hold a secret, matched by name at any depth: the approver's PIN of a void, a person's short code and their
     * password (People). None of them is part of a fingerprint, and an answer carrying one is stored encrypted. The migration
     * 2026_11_03_100100_clear_secret_fingerprints_of_idempotency_keys keeps its own copy of the list for the rows written before.
     */
    public const array SECRET_FIELDS = ['password', 'pin', 'short_code'];

    public function __construct(
        private readonly CurrentBranch $branch,
        private readonly ActorContext $actor,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->getMethod(), self::WRITE_METHODS, true)) {
            return $next($request);
        }

        $key = trim((string) $request->header(self::HEADER, ''));
        if ($key === '' || strlen($key) > 255) {
            return ApiError::json('idempotency.key_required', 400);
        }

        if (! $this->branch->isResolved() || ! $this->actor->isAuthenticated()) {
            throw new LogicException('The idempotency middleware needs a resolved CurrentBranch and an authenticated ActorContext; register it after authentication.');
        }

        $branchId = $this->branch->id();
        $userId = $this->actor->requireUserId();
        $fingerprint = self::fingerprint($request);
        $carriesSecret = self::holdsSecret($request->all());

        $lock = Cache::lock(self::lockName($branchId, $userId, $key), self::lockSeconds());
        if (! $lock->get()) {
            return ApiError::json('idempotency.in_flight', 409);
        }

        try {
            $stored = IdempotencyKey::query()
                ->where('branch_id', $branchId)
                ->where('user_id', $userId)
                ->where('key', $key)
                ->first();

            if ($stored !== null && $stored->expires_at->isPast()) {
                $stored->delete();
                $stored = null;
            }

            if ($stored !== null) {
                if (! hash_equals($stored->request_hash, $fingerprint)) {
                    return ApiError::json('idempotency.key_reused', 422);
                }
                if ($stored->response_status !== null && $stored->response_body !== null) {
                    // an encrypted answer the app key no longer opens cannot be replayed, and running the write again could repeat it
                    return self::replay($stored) ?? ApiError::json('idempotency.key_reused', 422);
                }
            }

            $response = $next($request);

            $snapshot = self::keeps($response->getStatusCode(), $carriesSecret) ? self::snapshot($request, $response) : null;
            if ($snapshot !== null) {
                IdempotencyKey::query()->updateOrCreate(
                    ['branch_id' => $branchId, 'user_id' => $userId, 'key' => $key],
                    [
                        'request_hash' => $fingerprint,
                        'response_status' => $response->getStatusCode(),
                        'response_body' => $snapshot,
                        'expires_at' => CarbonImmutable::now()->addHours(self::ttlHours()),
                    ],
                );
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    /** The lock name for one (branch, user, key); public so tests can hold it. */
    public static function lockName(int $branchId, int $userId, string $key): string
    {
        return sprintf('idempotency:%d:%d:%s', $branchId, $userId, hash('sha256', $key));
    }

    /**
     * Whether an answer is stored: never a server error (the client may retry), and for a request that carried a secret only a success,
     * because a refusal there may be about the secret itself (a wrong or malformed PIN) and the fingerprint cannot tell two PINs apart.
     */
    private static function keeps(int $status, bool $carriesSecret): bool
    {
        if ($status >= 500) {
            return false;
        }

        return ! $carriesSecret || ($status >= 200 && $status < 300);
    }

    /**
     * sha256 over method, path and the canonically ordered input without its secret fields: the same key with other input is a reuse. For a
     * multipart request each uploaded file stands for the sha256 of its bytes (never the whole body, and never its client-chosen name or
     * temporary path), so the same key with the same picture replays and the same key with another picture is a reuse (step 4.2b).
     */
    private static function fingerprint(Request $request): string
    {
        $input = self::withoutSecrets(self::withFileHashes($request->all()));
        self::sortKeysDeep($input);

        return hash('sha256', $request->getMethod().' '.$request->path().' '.json_encode(
            $input,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function withFileHashes(array $value): array
    {
        foreach ($value as $name => $item) {
            if ($item instanceof UploadedFile) {
                $path = $item->isValid() ? $item->getRealPath() : false;
                $hash = $path === false ? false : hash_file('sha256', $path);
                $value[$name] = ['uploaded_file_sha256' => $hash === false ? 'unreadable:'.$item->getError() : $hash];
            } elseif (is_array($item)) {
                $value[$name] = self::withFileHashes($item);
            }
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function withoutSecrets(array $value): array
    {
        foreach ($value as $name => $item) {
            if (in_array($name, self::SECRET_FIELDS, true)) {
                unset($value[$name]);
            } elseif (is_array($item)) {
                $value[$name] = self::withoutSecrets($item);
            }
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function holdsSecret(array $value): bool
    {
        foreach ($value as $name => $item) {
            if (in_array($name, self::SECRET_FIELDS, true) || (is_array($item) && self::holdsSecret($item))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function sortKeysDeep(array &$value): void
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                self::sortKeysDeep($item);
            }
        }
        unset($item);
    }

    /**
     * The answer as it is stored: JSON, raw text, or encrypted when it carries a secret. Null when an answer with a secret cannot be encrypted
     * (no usable app key): it is then not stored at all, never in clear, the client still gets it, and a retry runs the write again.
     *
     * @return array<string, mixed>|null
     */
    private static function snapshot(Request $request, Response $response): ?array
    {
        $content = (string) $response->getContent();
        $contentType = (string) $response->headers->get('Content-Type', 'text/plain');
        if (str_contains($contentType, 'json')) {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                if (! self::holdsSecret($decoded)) {
                    return ['kind' => 'json', 'data' => $decoded];
                }
                try {
                    return ['kind' => 'encrypted', 'data' => Crypt::encryptString($content)];
                } catch (Throwable $e) {
                    Log::warning('idempotency: an answer that carries a secret was not stored, the app key cannot encrypt it', [
                        'path' => $request->path(),
                        'error' => $e::class,
                    ]);

                    return null;
                }
            }
        }

        return ['kind' => 'raw', 'data' => $content, 'content_type' => $contentType];
    }

    /** The stored answer again, or null when an encrypted one cannot be opened (the app key changed or is unusable, or the row was altered). */
    private static function replay(IdempotencyKey $stored): ?Response
    {
        $snapshot = $stored->response_body ?? [];
        $status = (int) $stored->response_status;
        $data = $snapshot['data'] ?? null;

        if (($snapshot['kind'] ?? 'raw') === 'encrypted') {
            try {
                $data = json_decode(Crypt::decryptString(is_string($data) ? $data : ''), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return null;
            }

            return (new JsonResponse(is_array($data) ? $data : [], $status))->header(self::REPLAYED_HEADER, 'true');
        }

        if (($snapshot['kind'] ?? 'raw') === 'json') {
            $response = new JsonResponse(is_array($data) ? $data : [], $status);
        } else {
            $contentType = $snapshot['content_type'] ?? 'text/plain';
            $response = new HttpResponse(is_string($data) ? $data : '', $status, [
                'Content-Type' => is_string($contentType) ? $contentType : 'text/plain',
            ]);
        }

        return $response->header(self::REPLAYED_HEADER, 'true');
    }

    private static function ttlHours(): int
    {
        $hours = config('bakery.idempotency.ttl_hours');

        return is_int($hours) && $hours > 0 ? $hours : 24;
    }

    private static function lockSeconds(): int
    {
        $seconds = config('bakery.idempotency.lock_seconds');

        return is_int($seconds) && $seconds > 0 ? $seconds : 60;
    }
}
