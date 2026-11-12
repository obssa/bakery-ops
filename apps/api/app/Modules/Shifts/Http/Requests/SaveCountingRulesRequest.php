<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Requests;

use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PUT /counting-rules (ADR 0025): the stations to change and, for each, any of the three rules. Every station named must be one of the
 * branch's stations that runs shifts (another branch's, a removed one or the warehouse is a 422 on its id), each at most once, and each
 * entry must change at least one rule. Stations not named keep their rules.
 */
final class SaveCountingRulesRequest extends FormRequest
{
    public const array RULES = ['opening_count', 'cosign', 'approval'];

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

        return [
            'stations' => ['required', 'array', 'min:1', 'max:50'],
            'stations.*' => ['required', 'array'],
            'stations.*.id' => ['required', 'integer', 'distinct',
                Rule::exists('stations', 'id')->where('branch_id', $branchId)->whereNull('deleted_at')->where('tracks_shifts', true)],
            'stations.*.opening_count' => ['sometimes', 'boolean'],
            'stations.*.cosign' => ['sometimes', 'boolean'],
            'stations.*.approval' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $stations = $this->input('stations');
            if (! is_array($stations)) {
                return;
            }
            foreach ($stations as $index => $entry) {
                if (is_array($entry) && array_intersect(self::RULES, array_keys($entry)) === []) {
                    $validator->errors()->add("stations.{$index}", (string) trans('shifts.rules.name_one'));
                }
            }
        }];
    }

    /**
     * @return array<int, array{opening_count?: bool, cosign?: bool, approval?: bool}> the changes by station id
     */
    public function changes(): array
    {
        /** @var list<array<string, mixed>> $stations */
        $stations = $this->validated('stations');
        $out = [];
        foreach ($stations as $entry) {
            $rules = [];
            foreach (self::RULES as $rule) {
                if (array_key_exists($rule, $entry)) {
                    $rules[$rule] = (bool) $entry[$rule];
                }
            }
            $out[(int) $entry['id']] = $rules;
        }

        return $out;
    }
}
