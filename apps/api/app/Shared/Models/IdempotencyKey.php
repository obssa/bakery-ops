<?php

declare(strict_types=1);

namespace App\Shared\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One stored response per (branch_id, user_id, key) (ADR 0003). Written only by the Idempotency middleware;
 * scoped explicitly there, so it does not use BranchScoped. A row is replayed until expires_at; nothing sweeps it: an
 * expired row is deleted when its own key comes back, never by a user. request_hash leaves the secret fields out
 * (Idempotency::SECRET_FIELDS), and response_body holds {kind: json|raw|encrypted, data} (an answer carrying a secret is
 * encrypted with the app key, step 4.3e).
 *
 * @property int $id
 * @property int $branch_id
 * @property int $user_id
 * @property string $key
 * @property string $request_hash
 * @property int|null $response_status
 * @property array<string, mixed>|null $response_body
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class IdempotencyKey extends Model
{
    protected $table = 'idempotency_keys';

    /** @var list<string> */
    protected $fillable = ['branch_id', 'user_id', 'key', 'request_hash', 'response_status', 'response_body', 'expires_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'user_id' => 'integer',
            'response_status' => 'integer',
            'response_body' => 'array',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
