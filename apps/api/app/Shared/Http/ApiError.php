<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Illuminate\Http\JsonResponse;

/**
 * Bilingual API error bodies (rule 9): a stable message_key plus message_en and message_am from lang/<locale>/shared.php.
 * Clients branch on message_key and show the message in the user's locale.
 */
final class ApiError
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public static function json(string $key, int $status, array $extra = []): JsonResponse
    {
        return response()->json(self::body($key, $extra), $status);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function body(string $key, array $extra = []): array
    {
        return [
            'message_key' => $key,
            'message_en' => self::message($key, 'en'),
            'message_am' => self::message($key, 'am'),
        ] + $extra;
    }

    /**
     * A message_key is a lang key: `identity.short_code.locked` reads lang/<locale>/identity.php (each module owns its
     * dictionary pair); a bare key without a lang file of its own (`idempotency.key_required`) reads shared.php.
     */
    private static function message(string $key, string $locale): string
    {
        $message = trans($key, [], $locale);
        if (! is_string($message) || $message === $key) {
            $message = trans('shared.'.$key, [], $locale);
        }

        return is_string($message) ? $message : 'shared.'.$key;
    }
}
