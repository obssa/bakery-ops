<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Step 4.3e. Until this step the Idempotency middleware stored a sha256 of the whole request body as request_hash, and on the PIN voids
 * (POST /orders/{order}/void, POST /payments/{payment}/void) and the People writes (POST /users with a password and a short code, PATCH
 * /users/{id} with a password) that body held a secret: whoever can read idempotency_keys could recover a 4 to 6 digit PIN offline by
 * hashing the rest of the body with every candidate. The short-code reset also stored its answer, the new code, in clear.
 *
 * A row is replayed for 24 hours (bakery.idempotency.ttl_hours), but nothing purges it: an expired row is deleted only when its own key comes
 * back. So the live table still holds every write since Phase 1, and this migration deals with them. It deletes no row. The table does not
 * record the route, so a row is judged by what it holds; release.sh runs migrations in maintenance mode, so every row here was written by
 * the old code. request_hash becomes CLEARED, which no sha256 can equal (the key can never replay; the fixed middleware leaves the secret out
 * of its fingerprint, so a retry of an old PIN request could not have matched either), for:
 *   - every expired row: it never replays (the middleware deletes it first), and every row of a PIN route older than the TTL is one;
 *   - every unexpired refusal: a refusal wrote nothing, and the refusals of a PIN route (a wrong PIN, a locked prompt, a refused void, a
 *     validation error) are among them;
 *   - every unexpired success that is the answer of a route with a secret: a voided order or payment (`status` voided), a user
 *     (`has_short_code`), a short-code reset, or any answer holding a secret field.
 * An unexpired success of any other route keeps its hash and still replays. A secret field in a stored answer (the reset's short_code) is
 * set to null. A second run changes nothing. The fields are this file's own copy of Idempotency::SECRET_FIELDS, as the old rows knew them.
 */
return new class extends Migration
{
    private const string CLEARED = 'cleared-secret-fields-were-in-this-fingerprint------------------';

    private const array SECRET_FIELDS = ['password', 'pin', 'short_code'];

    public function up(): void
    {
        $now = CarbonImmutable::now();

        DB::table('idempotency_keys')->where('expires_at', '<=', $now)->where('request_hash', '<>', self::CLEARED)->select('id')
            ->chunkById(1000, function (Collection $rows): void {
                $this->clear($rows->pluck('id')->all());
            });

        DB::table('idempotency_keys')->where('expires_at', '>', $now)->where('request_hash', '<>', self::CLEARED)
            ->select(['id', 'response_status', 'response_body'])
            ->chunkById(500, function (Collection $rows): void {
                $this->clear($rows->filter(fn (stdClass $row): bool => $this->mayHoldSecret($row))->pluck('id')->all());
            });

        $body = DB::getDriverName() === 'pgsql' ? 'CAST(response_body AS TEXT)' : 'response_body';
        DB::table('idempotency_keys')
            ->where(function ($query) use ($body): void {
                foreach (self::SECRET_FIELDS as $field) {
                    $query->orWhereRaw($body.' LIKE ?', ['%"'.$field.'"%']);
                }
            })
            ->select(['id', 'response_body'])
            ->chunkById(500, function (Collection $rows): void {
                foreach ($rows as $row) {
                    $snapshot = $this->decode($row->response_body);
                    if (($snapshot['kind'] ?? null) !== 'json' || ! is_array($snapshot['data'] ?? null)) {
                        continue;
                    }
                    $blanked = $this->blank($snapshot['data']);
                    if ($blanked !== $snapshot['data']) {
                        $snapshot['data'] = $blanked;
                        DB::table('idempotency_keys')->where('id', $row->id)->update([
                            'response_body' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]);
                    }
                }
            });
    }

    /** The cleared hashes cannot be put back. */
    public function down(): void {}

    /**
     * @param  array<array-key, mixed>  $ids
     */
    private function clear(array $ids): void
    {
        if ($ids !== []) {
            DB::table('idempotency_keys')->whereIn('id', $ids)->update(['request_hash' => self::CLEARED]);
        }
    }

    private function mayHoldSecret(stdClass $row): bool
    {
        $status = $row->response_status === null ? null : (int) $row->response_status;
        if ($status === null || $status < 200 || $status >= 300) {
            return true;
        }
        $snapshot = $this->decode($row->response_body);
        $data = $snapshot['data'] ?? null;
        if (($snapshot['kind'] ?? null) !== 'json' || ! is_array($data)) {
            return false;
        }

        return ($data['status'] ?? null) === 'voided' || array_key_exists('has_short_code', $data) || $this->holdsSecret($data);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function holdsSecret(array $data): bool
    {
        foreach ($data as $name => $item) {
            if (in_array($name, self::SECRET_FIELDS, true) || (is_array($item) && $this->holdsSecret($item))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function blank(array $data): array
    {
        foreach ($data as $name => $item) {
            if (in_array($name, self::SECRET_FIELDS, true)) {
                $data[$name] = null;
            } elseif (is_array($item)) {
                $data[$name] = $this->blank($item);
            }
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $body): array
    {
        $decoded = is_string($body) ? json_decode($body, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
};
