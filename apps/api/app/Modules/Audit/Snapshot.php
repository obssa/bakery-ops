<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * What an audit row stores about a subject: its raw stored attributes (so decimal strings stay strings, ADR 0004)
 * with secrets replaced by a marker (a payment's reference number is one: it is never logged). Keys are matched case-insensitively; a null stays null so "unset -> set" is
 * still visible in a diff without the value ever being.
 */
final class Snapshot
{
    public const string REDACTED = '[redacted]';

    private const string SENSITIVE_KEY_RX = '/(password|secret|token|api_key|_hash$|^pin$|^reference_no$)/i';

    /**
     * @return array<string, mixed>
     */
    public static function of(Model $model): array
    {
        return self::redact($model->getAttributes());
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function redact(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if ($value !== null && preg_match(self::SENSITIVE_KEY_RX, (string) $key) === 1) {
                $attributes[$key] = self::REDACTED;
            }
        }

        return $attributes;
    }
}
