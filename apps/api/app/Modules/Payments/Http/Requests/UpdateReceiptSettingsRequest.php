<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Models\ReceiptSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /receipt-settings (phase-4 plan section 4.2 and M5, step 4.3e): the whole form each time, so a field left out is cleared. The
 * business name in both languages (up to 120); the address and the footer as bilingual pairs (both or neither, up to 255 each); a phone of
 * digits, spaces, +, - and brackets; a TIN of letters, digits and dashes (up to 32); show_amharic and ethiopian_dates as booleans.
 * Step 5.3c (phase-5 plan 10.2 D18): paper_width_mm (58 or 80) and print_logo (a boolean), each optional: left out, the saved value
 * stays (a till that cached the screen before 5.3c sends the form without them, and must not turn a 58 mm printer back to 80 mm).
 * The go-live settings (2026-11-02): printing_enabled (a boolean), optional the same way: left out, the saved switch stays.
 * Anything else is 422 validation.failed.
 */
final class UpdateReceiptSettingsRequest extends FormRequest
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
            'business_name_en' => ['required', 'string', 'max:120'],
            'business_name_am' => ['required', 'string', 'max:120'],
            'address_en' => ['nullable', 'string', 'max:255', 'required_with:address_am'],
            'address_am' => ['nullable', 'string', 'max:255', 'required_with:address_en'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9][0-9 ()\-]{4,31}$/'],
            'tin' => ['nullable', 'string', 'max:32', 'regex:/^[0-9A-Za-z\-]{1,32}$/'],
            'footer_en' => ['nullable', 'string', 'max:255', 'required_with:footer_am'],
            'footer_am' => ['nullable', 'string', 'max:255', 'required_with:footer_en'],
            'show_amharic' => ['required', 'boolean'],
            'ethiopian_dates' => ['required', 'boolean'],
            'paper_width_mm' => ['sometimes', 'integer', Rule::in(ReceiptSettings::PAPER_WIDTHS)],
            'print_logo' => ['sometimes', 'boolean'],
            'printing_enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The form as the row stores it: every field, the text ones left out as null; the paper, the logo switch and the printing switch only
     * when sent.
     *
     * @return array{business_name_en: string, business_name_am: string, address_en: string|null, address_am: string|null, phone: string|null, tin: string|null, footer_en: string|null, footer_am: string|null, show_amharic: bool, ethiopian_dates: bool, paper_width_mm?: int, print_logo?: bool, printing_enabled?: bool}
     */
    public function settings(): array
    {
        $data = $this->validated();
        $text = static fn (string $key): ?string => isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '' ? $data[$key] : null;

        $settings = [
            'business_name_en' => (string) $text('business_name_en'),
            'business_name_am' => (string) $text('business_name_am'),
            'address_en' => $text('address_en'),
            'address_am' => $text('address_am'),
            'phone' => $text('phone'),
            'tin' => $text('tin'),
            'footer_en' => $text('footer_en'),
            'footer_am' => $text('footer_am'),
            'show_amharic' => filter_var($data['show_amharic'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'ethiopian_dates' => filter_var($data['ethiopian_dates'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
        if (isset($data['paper_width_mm']) && is_numeric($data['paper_width_mm'])) {
            $settings['paper_width_mm'] = (int) $data['paper_width_mm'];
        }
        if (array_key_exists('print_logo', $data)) {
            $settings['print_logo'] = filter_var($data['print_logo'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('printing_enabled', $data)) {
            $settings['printing_enabled'] = filter_var($data['printing_enabled'], FILTER_VALIDATE_BOOLEAN);
        }

        return $settings;
    }
}
