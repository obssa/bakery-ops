<?php

declare(strict_types=1);

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /production/requests/{request}/assign: the person who makes it, by id. Whether they are an active person of this branch
 * who holds production.fulfil at the request's station is the action's question (AssignRequest).
 */
final class AssignProductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['user_id' => ['required', 'integer', 'min:1']];
    }

    public function userId(): int
    {
        return (int) $this->validated()['user_id'];
    }
}
