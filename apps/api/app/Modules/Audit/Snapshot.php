<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * What an audit row stores about a subject: its raw stored attributes (so decimal strings stay strings, ADR 0004) with secrets replaced by a
 * marker. A null stays null so "unset -> set" is still visible in a diff without the value ever being. The rules, applied at every depth of
 * an array, on write (AuditedAction: the snapshots, an event's payload and, from step 4.5a, the context) and on every read of the audit-log
 * API (step 4.5a: rows written before a rule existed keep what they were written with, and audit_log is append-only, so they are redacted
 * when they are read, never rewritten):
 *
 *   by key, case-insensitively: passwords, secrets, tokens, api keys, *_hash, a PIN, a payment's reference number and its normalised form
 *     (it is never logged), a receipt photo's object key (step 4.3b), and from step 4.5a which stored file a payment's receipt or a refund's
 *     proof is (receipt_file_id, proof_file_id: the audit trail says a photo was attached, the payment reads show it within their own scope),
 *     an object key of the file store (key, object_key, *_object_key) and a device's fingerprint (the X-Device-Id a pad sends);
 *   by value, in any text: a signed URL (a signed route of the application or a presigned bucket URL: a bearer credential while it lives)
 *     and an object key of the file store ({purpose}/{branch}/{yyyy}/{mm}/{uuid}.{ext}), each replaced where it stands, the rest of the text
 *     kept; a text that holds a JSON object or list (a JSON column as the model stores it, such as a notification's data) is redacted inside
 *     by the same rules and kept exactly as it was when there is nothing to hide.
 *
 * Redacting what is redacted changes nothing, so the read side can apply the rules to any row, old or new.
 */
final class Snapshot
{
    public const string REDACTED = '[redacted]';

    private const string SENSITIVE_KEY_RX = '/(password|secret|token|api_key|_hash$|^pin$|^reference_no$|^reference_norm$|^receipt_photo_key$'
        .'|^(receipt|proof)_file_id$|^key$|(^|_)object_key$|^fingerprint$)/i';

    /** A URL or a path whose query carries a signature: the token it stands in, up to a space, a quote or an angle bracket. */
    private const string SIGNED_URL_RX = '~[^\s"\'<>]*[?&](?:signature|x-amz-signature|x-amz-credential|x-goog-signature|x-goog-credential)=[^\s"\'<>]*~i';

    /** What SIGNED_URL_RX looks for, checked first so a long text without one costs a single scan. */
    private const string SIGNED_URL_HINT_RX = '~[?&](?:signature|x-amz-signature|x-amz-credential|x-goog-signature|x-goog-credential)=~i';

    /** An object key as Shared\Files\Files writes it (receipts/{branch_id}/{yyyy}/{mm}/{uuid}.jpg), also with a JSON text's escaped slashes. */
    private const string OBJECT_KEY_RX = '~\b[a-z_]+(?:\\\\?/)[0-9]+(?:\\\\?/)[0-9]{4}(?:\\\\?/)[0-9]{2}(?:\\\\?/)'
        .'[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.[a-z0-9]{2,5}\b~i';

    /**
     * @return array<string, mixed>
     */
    public static function of(Model $model): array
    {
        return self::redact($model->getAttributes());
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $attributes
     * @return array<TKey, mixed>
     */
    public static function redact(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            $attributes[$key] = self::value((string) $key, $value);
        }

        return $attributes;
    }

    /** null stays null; a sensitive key hides its whole value; an array is redacted inside; a text loses its signed URLs and object keys. */
    private static function value(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (preg_match(self::SENSITIVE_KEY_RX, $key) === 1) {
            return self::REDACTED;
        }
        if (is_array($value)) {
            return self::redact($value);
        }

        return is_string($value) ? self::text($value) : $value;
    }

    private static function text(string $text): string
    {
        $trimmed = ltrim($text);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                $redacted = self::redact($decoded);

                return $redacted === $decoded ? $text : (string) json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }
        if (preg_match(self::SIGNED_URL_HINT_RX, $text) === 1) {
            $text = (string) preg_replace(self::SIGNED_URL_RX, self::REDACTED, $text);
        }

        return (string) preg_replace(self::OBJECT_KEY_RX, self::REDACTED, $text);
    }
}
