<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /shifts/{shift}/notes: the client UUID of the note (an offline replay writes nothing twice), its text (1 to 1000 characters in any
 * language, trimmed by the framework) and optionally the colleague it is for (whether they may read the station is the action's question).
 */
final class StoreHandoverNoteRequest extends FormRequest
{
    public const int MAX_BODY = 1000;

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
            'client_uuid' => ['required', 'uuid'],
            'body' => ['required', 'string', 'max:'.self::MAX_BODY],
            'to_user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function clientUuid(): string
    {
        return strtolower((string) $this->validated()['client_uuid']);
    }

    public function text(): string
    {
        return (string) $this->validated()['body'];
    }

    public function toUserId(): ?int
    {
        $id = $this->validated()['to_user_id'] ?? null;

        return $id === null ? null : (int) $id;
    }
}
