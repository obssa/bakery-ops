<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Requests;

use App\Shared\Models\Station;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * GET /replay: `station_id` (a station of the branch), `at` (ISO-8601; with an offset or Z it is that instant, a bare time is the branch's
 * local time, section 10.2 of the plan) and `nonzero` (default on: only balances that are not zero).
 */
final class ReplayRequest extends FormRequest
{
    private const string ISO = '/\A\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})?\z/';

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
            'station_id' => ['required', 'integer', 'min:1'],
            'at' => ['required', 'string', 'regex:'.self::ISO],
            'nonzero' => ['sometimes', 'boolean'],
        ];
    }

    /** The station of the branch, else 422 validation.failed on station_id (the station is a filter here, not the resource). */
    public function station(): Station
    {
        return Station::query()->withTrashed()->find((int) $this->validated()['station_id'])
            ?? throw ValidationException::withMessages(['station_id' => ['The selected station id is invalid.']]);
    }

    /** The instant asked for, in UTC. */
    public function instant(): CarbonImmutable
    {
        $text = (string) $this->validated()['at'];
        $zone = preg_match('/(Z|[+-]\d{2}:?\d{2})\z/', $text) === 1 ? null : app(CurrentBranch::class)->timezone();

        return CarbonImmutable::parse($text, $zone)->utc();
    }

    public function nonzero(): bool
    {
        return $this->boolean('nonzero', true);
    }
}
