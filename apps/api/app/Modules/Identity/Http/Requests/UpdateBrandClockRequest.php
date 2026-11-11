<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Shared\Time\EthiopianClock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /brand/clock (Phase 6 follow-up, the Ethiopian clock): the international clock shown beside the Ethiopian one, none (the Ethiopian
 * clock alone, the default), h12 ("4:10 PM") or h24 ("16:10"). Anything else is 422 validation.failed.
 */
final class UpdateBrandClockRequest extends FormRequest
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
        return [
            'clock_also' => ['required', 'string', Rule::in(EthiopianClock::ALSO)],
        ];
    }

    public function also(): string
    {
        return (string) $this->validated()['clock_also'];
    }
}
