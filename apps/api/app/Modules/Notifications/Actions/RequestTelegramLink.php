<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Notifications\Drivers\DriverRegistry;
use App\Modules\Notifications\Exceptions\NotificationsError;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * POST /notifications/telegram/link (plan section 4): a one-time code the caller sends to the bot as "/start CODE" to link
 * their Telegram chat. The code is kept only in the cache for config('notifications.telegram_link_minutes') (10) minutes,
 * naming the person and the branch; nothing is written to the database but the audit row (notification.telegram.link_requested,
 * without the code). A driver that is off is refused (notifications.driver.disabled). The same Idempotency-Key replays the
 * same code. Actions\LinkTelegramChat consumes it.
 */
final class RequestTelegramLink
{
    public const string CACHE_PREFIX = 'notifications:telegram-link:';

    /** no 0, O, 1, I or L: a code is read off a screen and typed in */
    private const string ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly AuditedAction $audit,
        private readonly DriverRegistry $drivers,
        private readonly CurrentBranch $branch,
    ) {}

    /**
     * @return array{code: string, expires_at: string, deep_link: string|null}
     */
    public function handle(int $userId): array
    {
        if (! $this->drivers->isOn('telegram')) {
            throw NotificationsError::driverDisabled('telegram');
        }
        $minutes = max(1, (int) config('notifications.telegram_link_minutes', 10));
        $expiresAt = CarbonImmutable::now()->addMinutes($minutes);

        $code = '';
        do {
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (! Cache::add(self::CACHE_PREFIX.$code, [
            'user_id' => $userId,
            'branch_id' => $this->branch->id(),
            'expires_at' => $expiresAt->getTimestamp(),
        ], $expiresAt));

        $this->audit->recordEvent('notification.telegram.link_requested', 'notification_endpoint', null, ['user_id' => $userId, 'expires_at' => $expiresAt->toIso8601String()]);
        $username = $this->drivers->telegramBotUsername();

        return [
            'code' => $code,
            'expires_at' => $expiresAt->toIso8601String(),
            'deep_link' => $username === null ? null : 'https://t.me/'.Str::of($username)->ltrim('@')->toString().'?start='.$code,
        ];
    }
}
