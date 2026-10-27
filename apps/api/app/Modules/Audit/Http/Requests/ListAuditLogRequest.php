<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Requests;

use App\Modules\Audit\Read\AuditLogQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /audit-log (phase-4 plan section 4.7, step 4.5a): local dates (YYYY-MM-DD, from not after to), a person (actor_id), an action prefix,
 * a subject (type, id), a station, a shift, a cursor (the last id seen) and a limit (1 to 200, 50 by default). Anything malformed is 422
 * validation.failed. Read\AuditLogQuery applies them.
 */
final class ListAuditLogRequest extends FormRequest
{
    /** @var list<string> the filters, in the order the query reads them */
    public const array FILTERS = ['from', 'to', 'actor_id', 'action', 'subject_type', 'subject_id', 'station_id', 'shift_id'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return self::filterRules($this->has('from')) + [
            'cursor' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.AuditLogQuery::MAX_LIMIT],
        ];
    }

    /**
     * The rules of the filters, shared with the export (ExportAuditLogRequest).
     *
     * @return array<string, list<mixed>>
     */
    public static function filterRules(bool $hasFrom): array
    {
        $id = ['sometimes', 'integer', 'min:1'];

        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => array_merge(['sometimes', 'date_format:Y-m-d'], $hasFrom ? ['after_or_equal:from'] : []),
            'actor_id' => $id,
            'action' => ['sometimes', 'string', 'max:100'],
            'subject_type' => ['sometimes', 'string', 'max:191'],
            'subject_id' => $id,
            'station_id' => $id,
            'shift_id' => $id,
        ];
    }

    /**
     * The validated filters, the cursor and the limit as strings, in a fixed order.
     *
     * @return array<string, string>
     */
    public function filters(): array
    {
        return self::pick($this->validated(), [...self::FILTERS, 'cursor', 'limit']);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    public static function pick(array $validated, array $keys): array
    {
        $filters = [];
        foreach ($keys as $key) {
            $value = $validated[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                $filters[$key] = (string) $value;
            }
        }

        return $filters;
    }
}
