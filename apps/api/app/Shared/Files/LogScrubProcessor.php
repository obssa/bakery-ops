<?php

declare(strict_types=1);

namespace App\Shared\Files;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Keeps a signed URL and an object key out of every log line (phase-4 plan 10.1 R-F). A signed URL is a bearer credential while it lives,
 * and an object key says where a customer's receipt is, so neither is ever written:
 *   - the `signature=` and `expires=` query parameters (and the S3 presigned `X-Amz-*` ones) are removed from the message, the context and
 *     the extra data, wherever they sit in the query string;
 *   - anything shaped like an object key (receipts/{branch}/...) is replaced by a placeholder.
 * Installed on every log channel by ScrubLogs (config/logging.php `tap`). Exceptions in the context are objects and are left to the
 * formatter; the files code throws messages without a URL or a key, so there is nothing to scrub in them.
 */
final class LogScrubProcessor implements ProcessorInterface
{
    public const string KEY_PLACEHOLDER = '[file key]';

    private const string PARAMS = 'signature|expires|X-Amz-Signature|X-Amz-Expires|X-Amz-Credential|X-Amz-Date|X-Amz-Algorithm|X-Amz-SignedHeaders|X-Amz-Security-Token';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: self::scrubString($record->message),
            context: self::scrubArray($record->context),
            extra: self::scrubArray($record->extra),
        );
    }

    public static function scrubString(string $text): string
    {
        if (! str_contains($text, '=') && ! str_contains($text, 'receipts/')) {
            return $text;
        }
        $text = (string) preg_replace('/([?&])(?:'.self::PARAMS.')=[^&\s"\'#<>]*/i', '$1', $text);
        $text = (string) preg_replace('/\?&+/', '?', $text);
        $text = (string) preg_replace('/&{2,}/', '&', $text);
        $text = (string) preg_replace('/[?&](?=[\s"\'#<>]|$)/', '', $text);

        return (string) preg_replace('#receipts/\d+/[A-Za-z0-9/_.-]+#', self::KEY_PLACEHOLDER, $text);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function scrubArray(array $values): array
    {
        foreach ($values as $name => $value) {
            if (is_string($value)) {
                $values[$name] = self::scrubString($value);
            } elseif (is_array($value)) {
                $values[$name] = self::scrubArray($value);
            }
        }

        return $values;
    }
}
