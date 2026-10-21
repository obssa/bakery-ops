<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Notifications\Drivers\DriverRegistry;
use App\Modules\Notifications\Models\NotificationEndpoint;
use App\Modules\Notifications\Support\EventScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * What the Telegram webhook does with an update (plan section 4): a private chat that sends "/start CODE" with a code
 * Actions\RequestTelegramLink handed out, still valid and not used before, is linked to the person who asked, in their branch,
 * as a telegram endpoint (one live row per person and chat; a link that was removed comes back). The code is consumed at the
 * first use. The chat is told the result in the person's language; anything else (a wrong, used or expired code, plain text,
 * /start alone) is answered with a short help in both languages, since the chat's language is not known. Groups, channels and
 * every update that is not a message are ignored. The reply is best effort: a failing Bot API never fails the webhook, which
 * always answers 200 so Telegram does not resend.
 *
 * Runs with no signed-in user: the code names the person and the branch, and the link is written, and audited
 * (notification.telegram.linked, without the chat id), as that person in that branch (Support\EventScope).
 */
final class LinkTelegramChat
{
    public function __construct(
        private readonly DriverRegistry $drivers,
        private readonly EventScope $scope,
        private readonly AuditedAction $audit,
        private readonly Recipients $recipients,
        private readonly ConnectionResolverInterface $db,
    ) {}

    /**
     * @param  array<string, mixed>  $update
     */
    public function handle(array $update): void
    {
        $message = $update['message'] ?? null;
        $chat = is_array($message) ? ($message['chat'] ?? null) : null;
        if (! is_array($message) || ! is_array($chat) || ($chat['type'] ?? null) !== 'private') {
            return;
        }
        $id = $chat['id'] ?? null;
        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return;
        }
        $chatId = (string) $id;
        $text = is_string($message['text'] ?? null) ? trim($message['text']) : '';

        if (preg_match('/^\/start(?:@\w+)?\s+([A-Za-z0-9]{4,32})$/', $text, $match) !== 1) {
            $this->reply($chatId, $this->both('help'));

            return;
        }
        $pending = Cache::pull(RequestTelegramLink::CACHE_PREFIX.strtoupper($match[1]));
        if (! is_array($pending) || (int) ($pending['expires_at'] ?? 0) < CarbonImmutable::now()->getTimestamp()) {
            $this->reply($chatId, $this->both('unknown_code'));

            return;
        }

        $locale = $this->scope->run((int) $pending['branch_id'], (int) $pending['user_id'], fn (): ?string => $this->link((int) $pending['user_id'], $chatId));
        $this->reply($chatId, $locale === null ? $this->both('unknown_code') : (string) trans('notifications.telegram.linked', [], $locale));
    }

    /** The person's language once the chat is linked; null when they are no longer an active user of the branch. */
    private function link(int $userId, string $chatId): ?string
    {
        $user = $this->recipients->byIds([$userId])->first();
        if ($user === null) {
            return null;
        }

        $this->db->connection()->transaction(function () use ($userId, $chatId): void {
            $existing = NotificationEndpoint::withTrashed()
                ->where('user_id', $userId)->where('driver', 'telegram')->where('address_hash', hash('sha256', $chatId))
                ->first();
            $endpoint = $existing ?? new NotificationEndpoint;
            $endpoint->forceFill([
                'user_id' => $userId,
                'driver' => 'telegram',
                'address' => $chatId,
                'verified_at' => CarbonImmutable::now(),
                'revoked_at' => null,
                'deleted_at' => null,
            ])->save();
            $this->audit->recordEvent('notification.telegram.linked', 'notification_endpoint', $endpoint->id, ['driver' => 'telegram', 'user_id' => $userId]);
        });

        return $this->recipients->localeOf($user);
    }

    /** One message in both languages: the chat has not told us which it reads. */
    private function both(string $key): string
    {
        return trans("notifications.telegram.{$key}", [], 'en')."\n".trans("notifications.telegram.{$key}", [], 'am');
    }

    private function reply(string $chatId, string $text): void
    {
        $bot = $this->drivers->bot();
        if ($bot === null) {
            return;
        }
        try {
            $bot->sendMessage($chatId, $text);
        } catch (Throwable) {
            // best effort: the link is already stored, and Telegram must get its 200
        }
    }
}
