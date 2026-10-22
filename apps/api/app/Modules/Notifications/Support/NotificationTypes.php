<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Notifications\Exceptions\MissingPayloadKey;
use App\Modules\Notifications\Exceptions\UnknownNotificationType;

/**
 * Reads the type registry (config/notifications.php): what a type is called, how severe it is, which channels it may use,
 * and how the inbox line, the delivery gate and the link are derived from an event payload. Nothing here is stored, so it is
 * all static and safe under Octane (it reads config() on each call).
 */
final class NotificationTypes
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::registry());
    }

    public static function has(string $type): bool
    {
        return array_key_exists($type, self::registry());
    }

    /**
     * The severity of the type; for the two Phase 3 types the payload decides: a count with lines that differ and a rejection are
     * `warning` (somebody must act), a count that matches and an approval are `info`.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function severity(string $type, array $payload = []): string
    {
        if ($type === 'count_submitted' && $payload !== []) {
            return (int) ($payload['variance_lines'] ?? 0) > 0 ? 'warning' : 'info';
        }
        if ($type === 'variance_decided' && $payload !== []) {
            return ($payload['decision'] ?? null) === 'rejected' ? 'warning' : 'info';
        }
        $severity = self::entry($type)['severity'] ?? null;

        return is_string($severity) ? $severity : 'info';
    }

    /**
     * @return list<string>
     */
    public static function channels(string $type): array
    {
        $channels = self::entry($type)['channels'] ?? ['in_app'];

        return is_array($channels) ? array_values(array_filter($channels, 'is_string')) : ['in_app'];
    }

    /**
     * @return list<string>
     */
    public static function notifyRoles(string $type): array
    {
        $roles = self::entry($type)['notify_roles'] ?? [];

        return is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
    }

    /**
     * The key that makes an inbox line unique per person and event (the unique (user_id, dedupe_key)).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function inboxKey(string $type, array $payload): string
    {
        return self::expand($type, (string) (self::entry($type)['inbox_key'] ?? ''), $payload);
    }

    /**
     * The key of the delivery gate of an episodic alert, or null for a type that is not gated.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function gateKey(string $type, array $payload): ?string
    {
        $template = self::entry($type)['gate'] ?? null;

        return is_string($template) && $template !== '' ? self::expand($type, $template, $payload) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function deepLink(string $type, array $payload): string
    {
        $byStation = self::entry($type)['deep_link_by_station'] ?? null;
        $code = $payload['station_code'] ?? null;
        if (is_array($byStation) && is_string($code) && is_string($byStation[$code] ?? null)) {
            return self::expand($type, $byStation[$code], $payload);
        }

        return self::expand($type, (string) (self::entry($type)['deep_link'] ?? '/'), $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private static function entry(string $type): array
    {
        $entry = self::registry()[$type] ?? null;

        return is_array($entry) ? $entry : throw UnknownNotificationType::named($type);
    }

    /**
     * @return array<string, mixed>
     */
    private static function registry(): array
    {
        $types = config('notifications.types', []);

        return is_array($types) ? $types : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function expand(string $type, string $template, array $payload): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', static function (array $match) use ($type, $payload): string {
            $value = $payload[$match[1]] ?? null;
            if (! is_int($value) && ! is_string($value)) {
                throw MissingPayloadKey::for($type, $match[1]);
            }

            return (string) $value;
        }, $template);
    }
}
