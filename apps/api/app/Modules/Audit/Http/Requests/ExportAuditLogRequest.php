<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Requests;

use App\Modules\Audit\Read\AuditExport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /audit-log/export (phase-4 plan section 4.7, step 4.5a): the filters of GET /audit-log (ListAuditLogRequest) without its paging (a
 * cursor or a limit is 422: the file holds every row the filters find), plus ?format=csv|xlsx (csv by default) and ?locale=en|am for the
 * headers (en by default).
 */
final class ExportAuditLogRequest extends FormRequest
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
        return ListAuditLogRequest::filterRules($this->has('from')) + [
            'cursor' => ['prohibited'],
            'limit' => ['prohibited'],
            'format' => ['sometimes', Rule::in(AuditExport::FORMATS)],
            'locale' => ['sometimes', Rule::in(['en', 'am'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function filters(): array
    {
        return ListAuditLogRequest::pick($this->validated(), ListAuditLogRequest::FILTERS);
    }

    public function fileFormat(): string
    {
        return ($this->validated()['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
    }

    public function exportLocale(): string
    {
        return ($this->validated()['locale'] ?? 'en') === 'am' ? 'am' : 'en';
    }
}
