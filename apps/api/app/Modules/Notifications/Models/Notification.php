<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Database\Factories\Notifications\NotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of one person's inbox (plan section 3, migration 10): the title and body in both languages, the payload of the
 * event it came from, and the path it opens. Written only by Contracts\Notifier (with the dedupe gate, in one transaction);
 * the only column that ever changes is read_at (Actions\MarkRead, Actions\MarkAllRead). History: never deleted, softly or
 * otherwise (tests/Arch/SoftDeletesTest.php). There is no updated_at: created_at, with microseconds, is the clock of the
 * polling cursor.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $user_id
 * @property string $type
 * @property string $severity
 * @property string $title_en
 * @property string $title_am
 * @property string $body_en
 * @property string $body_am
 * @property array<string, mixed> $payload
 * @property string $deep_link
 * @property string|null $dedupe_key
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable $created_at
 */
final class Notification extends Model
{
    use BranchScoped;

    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    protected $table = 'notifications';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @var list<string> nothing is mass assignable: the Notifier sets every column, read_at is set by the read actions */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'user_id' => 'integer',
            'payload' => 'array',
            'read_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): NotificationFactory
    {
        return NotificationFactory::new();
    }
}
