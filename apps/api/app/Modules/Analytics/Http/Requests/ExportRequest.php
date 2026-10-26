<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Requests;

/**
 * GET /analytics/exports/{report} (phase-4 plan section 4.6, step 4.4d): RangeRequest's ?from=&to= (the last 30 days by default, at most
 * 400, never after today), ?format=xlsx|pdf|csv (xlsx by default) and ?locale=en|am (English by default) for the sheet names, the headers,
 * the values and the names. Anything else wrong is 422 validation.failed.
 */
final class ExportRequest extends RangeRequest
{
    public const array FORMATS = ['xlsx', 'pdf', 'csv'];

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'format' => ['sometimes', 'in:'.implode(',', self::FORMATS)],
            'locale' => ['sometimes', 'in:en,am'],
        ];
    }

    public function fileFormat(): string
    {
        $format = $this->validated('format');

        return is_string($format) ? $format : 'xlsx';
    }

    public function exportLocale(): string
    {
        return $this->validated('locale') === 'am' ? 'am' : 'en';
    }
}
