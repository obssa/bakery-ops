<?php

declare(strict_types=1);

namespace App\Shared\Files;

use App\Shared\Http\ApiError;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Every refusal of the file store, as a stable message_key (lang/<locale>/files.php, rule 9) and the HTTP status it maps to.
 * Thrown before anything is stored; the key and the bytes of the file are never part of the message.
 */
final class FilesError extends RuntimeException
{
    /** @var list<string> every key this class can carry; FilesLangTest keeps both dictionaries in step */
    public const KEYS = [
        'files.not_an_image',
        'files.truncated',
        'files.too_many_pixels',
        'files.too_large',
        'files.unsupported_type',
        'files.unavailable',
        'files.processing_failed',
        'files.invalid_signature',
    ];

    /**
     * @param  array<string, mixed>  $extra
     */
    private function __construct(
        public readonly string $messageKey,
        public readonly int $status,
        public readonly array $extra = [],
    ) {
        parent::__construct($messageKey);
    }

    public static function notAnImage(): self
    {
        return new self('files.not_an_image', 422);
    }

    public static function truncated(): self
    {
        return new self('files.truncated', 422);
    }

    public static function tooManyPixels(int $maxPixels): self
    {
        return new self('files.too_many_pixels', 422, ['max_pixels' => $maxPixels]);
    }

    public static function tooLarge(int $maxBytes): self
    {
        return new self('files.too_large', 422, ['max_bytes' => $maxBytes]);
    }

    public static function unsupportedType(): self
    {
        return new self('files.unsupported_type', 422);
    }

    /** gd is missing on this host: the feature is off (the doctor says so first). */
    public static function unavailable(): self
    {
        return new self('files.unavailable', 503);
    }

    /** The re-encoded result did not pass the re-read. */
    public static function processingFailed(): self
    {
        return new self('files.processing_failed', 500);
    }

    public function render(): JsonResponse
    {
        return ApiError::json($this->messageKey, $this->status, $this->extra);
    }
}
