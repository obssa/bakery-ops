<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Contracts;

use App\Modules\Notifications\Exceptions\UnknownNotificationType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\Dedupe;
use App\Modules\Notifications\Support\NotificationText;
use App\Modules\Notifications\Support\NotificationTypes;
use App\Shared\Actor\ActorContext;
use App\Shared\Tenancy\BranchScope;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * manifest notifications.provides: "Notifier::send(users, type, payload, deepLink)" (phase-2 plan section 5). Writes the inbox
 * line of every recipient in ONE transaction together with, for an episodic alert, the delivery-gate row (Support\Dedupe).
 * Both languages are stored, written from the payload alone (Support\NotificationText); the reader's locale picks one when
 * the line is read, and the drivers of step 2.5b pick the recipient's when they deliver outside the app.
 *
 *  - A type the registry (config/notifications.php) does not list is a bug (UnknownNotificationType).
 *  - A gated type (stock_out) whose item and place people were already told about writes nothing until StockRecovered clears
 *    the gate; nobody to tell claims nothing (an alert is never spent on an empty audience); a gate that was claimed by a
 *    redelivery that wrote no line is given back, so it cannot swallow the next real episode.
 *  - A redelivered event writes one line per person, not two: the unique (user_id, dedupe_key); only the people who have no
 *    line for it yet get one. The cause of the event (payload.caused_by_user_id) is the actor the worker runs as.
 *
 * Returns the lines it wrote (none when the gate held or every line already existed). Resolved per use: it carries the scoped
 * CurrentBranch and ActorContext (traps.md, Octane). $users are the recipients Identity\Contracts\Recipients answered;
 * only their keys are read.
 */
final class Notifier
{
    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly NotificationText $text,
        private readonly Dedupe $dedupe,
        private readonly ActorContext $actor,
        private readonly CurrentBranch $branch,
    ) {}

    /**
     * @param  iterable<Model>  $users
     * @param  array<string, mixed>  $payload
     * @return list<Notification>
     */
    public function send(iterable $users, string $type, array $payload, ?string $deepLink = null): array
    {
        if (! NotificationTypes::has($type)) {
            throw UnknownNotificationType::named($type);
        }
        $branchId = $this->branch->id();
        $ids = [];
        foreach ($users as $user) {
            $id = $user->getKey();
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[(int) $id] = (int) $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $rendered = $this->text->render($type, $payload);
        if (($payload['caused_by_user_id'] ?? null) === null) {
            $payload['caused_by_user_id'] = $this->actor->effectiveUserId();
        }
        $row = [
            'branch_id' => $branchId,
            'type' => $type,
            'severity' => NotificationTypes::severity($type),
            'title_en' => $rendered->title->en,
            'title_am' => $rendered->title->am,
            'body_en' => $rendered->body->en,
            'body_am' => $rendered->body->am,
            'payload' => $payload,
            'deep_link' => $deepLink ?? NotificationTypes::deepLink($type, $payload),
            'dedupe_key' => NotificationTypes::inboxKey($type, $payload),
        ];
        $gate = NotificationTypes::gateKey($type, $payload);

        return $this->db->connection()->transaction(function () use ($ids, $row, $gate, $type, $branchId): array {
            if ($gate !== null && ! $this->dedupe->claim($gate, $type)) {
                return [];
            }
            $written = [];
            foreach ($ids as $userId) {
                $line = $this->write($userId, $row);
                if ($line !== null) {
                    $written[] = $line;
                }
            }
            if ($gate !== null && $written === []) {
                $this->dedupe->clear($branchId, $gate);
            }

            return $written;
        });
    }

    /**
     * One person's line, or null when they already have it. A pre-check keeps the usual redelivery free of errors; the
     * savepoint turns the race (two deliveries at once, the unique key refusing the second) into the same null instead of
     * an aborted transaction.
     *
     * @param  array<string, mixed>  $row
     */
    private function write(int $userId, array $row): ?Notification
    {
        $exists = Notification::query()->withoutGlobalScope(BranchScope::class)
            ->where('user_id', $userId)->where('dedupe_key', $row['dedupe_key'])->exists();
        if ($exists) {
            return null;
        }

        try {
            return $this->db->connection()->transaction(function () use ($userId, $row): Notification {
                $line = new Notification;
                $line->forceFill($row + ['user_id' => $userId, 'read_at' => null, 'created_at' => CarbonImmutable::now()])->save();

                return $line;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
