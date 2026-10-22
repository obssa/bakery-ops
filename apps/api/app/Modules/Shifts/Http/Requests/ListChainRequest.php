<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GET /locations/{location}/chain: `limit` (default 50, at most 200) and `before_seq` (links older than this number). */
final class ListChainRequest extends FormRequest
{
    public const int DEFAULT_LIMIT = 50;

    public const int MAX_LIMIT = 200;

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
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'before_seq' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function limit(): int
    {
        return (int) ($this->validated()['limit'] ?? self::DEFAULT_LIMIT);
    }

    public function beforeSeq(): ?int
    {
        $value = $this->validated()['before_seq'] ?? null;

        return $value === null ? null : (int) $value;
    }
}
