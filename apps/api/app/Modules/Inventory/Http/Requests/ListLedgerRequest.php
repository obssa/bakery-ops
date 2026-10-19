<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /ledger?item=&location=&from=&to=&per_page=: filters over the movements of the caller's branch. item and location
 * may be retired ones (their history stays readable). from / to are a date (a whole day in the branch's own calendar,
 * Africa/Addis_Ababa by default) or an ISO-8601 instant; both bounds are inclusive.
 */
final class ListLedgerRequest extends FormRequest
{
    private const array INSTANT_FORMATS = ['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $branchId = app(CurrentBranch::class)->idOrNull();
        $dateFormats = 'date_format:Y-m-d,'.implode(',', self::INSTANT_FORMATS);

        return [
            'item' => ['sometimes', 'integer', Rule::exists('items', 'id')->where('branch_id', $branchId)],
            'location' => ['sometimes', 'integer', Rule::exists('locations', 'id')->where('branch_id', $branchId)],
            'from' => ['sometimes', 'string', $dateFormats],
            // Inclusive upper bound; when from is given too, not before it.
            'to' => $this->has('from') ? ['sometimes', 'string', $dateFormats, 'after_or_equal:from'] : ['sometimes', 'string', $dateFormats],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function itemId(): ?int
    {
        $id = $this->validated()['item'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    public function locationId(): ?int
    {
        $id = $this->validated()['location'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    public function from(): ?CarbonImmutable
    {
        return $this->bound('from', false);
    }

    public function to(): ?CarbonImmutable
    {
        return $this->bound('to', true);
    }

    public function perPage(): int
    {
        $perPage = $this->validated()['per_page'] ?? null;

        return is_numeric($perPage) ? (int) $perPage : 50;
    }

    /** A bare date covers the whole day in the branch calendar; an instant is taken as given (UTC when it says none). */
    private function bound(string $key, bool $endOfDay): ?CarbonImmutable
    {
        $value = $this->validated()[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $timezone = app(CurrentBranch::class)->isResolved() ? app(CurrentBranch::class)->timezone() : (string) config('bakery.timezone');
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);
            if ($day === null) {
                return null;
            }

            return ($endOfDay ? $day->endOfDay() : $day->startOfDay())->utc();
        }

        return CarbonImmutable::parse($value, 'UTC')->utc();
    }
}
