<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /notifications?unread=&since=&limit=: the caller's own inbox (phase-2 plan section 4). unread=1 keeps the lines not
 * yet read; since is the cursor a previous answer handed back (a value that is not a plain number is a first load, like the
 * station rail's); limit is 1 to 100, 30 when not given.
 */
final class ListNotificationsRequest extends FormRequest
{
    public const int DEFAULT_LIMIT = 30;

    public const int MAX_LIMIT = 100;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'unread' => ['sometimes', 'boolean'],
            'since' => ['sometimes', 'nullable'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ];
    }

    public function unread(): bool
    {
        return (bool) ($this->validated()['unread'] ?? false);
    }

    public function limit(): int
    {
        return (int) ($this->validated()['limit'] ?? self::DEFAULT_LIMIT);
    }

    /** The cursor of a previous answer; null on a first load and for anything that is not a plain number. */
    public function since(): ?int
    {
        $since = $this->query('since');

        return is_string($since) && preg_match('/\A\d{1,18}\z/', $since) === 1 ? (int) $since : null;
    }
}
