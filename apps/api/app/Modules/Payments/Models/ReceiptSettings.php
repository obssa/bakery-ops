<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * What a branch's receipts print at the top and the bottom (M5, step 4.3e): one row per branch, saved by PUT /receipt-settings
 * (Http\Controllers\ReceiptSettingsController) and read by the receipt (Support\Receipts\ReceiptBuilder). A branch that has saved nothing has
 * no row and reads the defaults (defaults()). Changed in place, never deleted. Step 5.3a's M4 added the receipt printer's paper
 * (paper_width_mm, 58 or 80, the default 80: Support\Printing\ReceiptPrint draws 384 or 576 dots) and print_logo (reserved, false);
 * step 5.3c puts both in the form.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $business_name_en
 * @property string $business_name_am
 * @property string|null $address_en
 * @property string|null $address_am
 * @property string|null $phone
 * @property string|null $tin
 * @property string|null $footer_en
 * @property string|null $footer_am
 * @property bool $show_amharic
 * @property bool $ethiopian_dates
 * @property int $paper_width_mm
 * @property bool $print_logo
 * @property int $created_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ReceiptSettings extends Model
{
    use BranchScoped;

    /** @var list<string> the form PUT /receipt-settings saves; the branch and the creator are set by the controller */
    public const array FIELDS = [
        'business_name_en', 'business_name_am', 'address_en', 'address_am', 'phone', 'tin', 'footer_en', 'footer_am', 'show_amharic', 'ethiopian_dates',
        'paper_width_mm', 'print_logo',
    ];

    /** @var list<int> the receipt printer's paper in mm (phase-5 plan 10.2 D18): 80 prints 576 dots, 58 prints 384 */
    public const array PAPER_WIDTHS = [58, 80];

    public const int DEFAULT_PAPER_WIDTH = 80;

    protected $table = 'receipt_settings';

    /** @var list<string> */
    protected $fillable = self::FIELDS;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'show_amharic' => 'boolean',
            'ethiopian_dates' => 'boolean',
            'paper_width_mm' => 'integer',
            'print_logo' => 'boolean',
            'created_by_user_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * The settings as GET and PUT /receipt-settings answer them: the saved row, or the defaults of a branch that saved nothing.
     *
     * @return array{business_name_en: string, business_name_am: string, address_en: string|null, address_am: string|null, phone: string|null, tin: string|null, footer_en: string|null, footer_am: string|null, show_amharic: bool, ethiopian_dates: bool, paper_width_mm: int, print_logo: bool, is_default: bool}
     */
    public static function view(?self $row, string $branchName): array
    {
        if (! $row instanceof self) {
            return self::defaults($branchName);
        }

        return [
            'business_name_en' => $row->business_name_en,
            'business_name_am' => $row->business_name_am,
            'address_en' => $row->address_en,
            'address_am' => $row->address_am,
            'phone' => $row->phone,
            'tin' => $row->tin,
            'footer_en' => $row->footer_en,
            'footer_am' => $row->footer_am,
            'show_amharic' => $row->show_amharic,
            'ethiopian_dates' => $row->ethiopian_dates,
            'paper_width_mm' => $row->paper_width_mm,
            'print_logo' => $row->print_logo,
            'is_default' => false,
        ];
    }

    /**
     * What a branch that has saved nothing reads (phase-4 plan M5): its name in both languages, no address, phone, TIN or footer, Amharic
     * shown, Ethiopian dates off; 80 mm paper and no logo (M4's column defaults).
     *
     * @return array{business_name_en: string, business_name_am: string, address_en: string|null, address_am: string|null, phone: string|null, tin: string|null, footer_en: string|null, footer_am: string|null, show_amharic: bool, ethiopian_dates: bool, paper_width_mm: int, print_logo: bool, is_default: bool}
     */
    public static function defaults(string $branchName): array
    {
        return [
            'business_name_en' => $branchName,
            'business_name_am' => $branchName,
            'address_en' => null,
            'address_am' => null,
            'phone' => null,
            'tin' => null,
            'footer_en' => null,
            'footer_am' => null,
            'show_amharic' => true,
            'ethiopian_dates' => false,
            'paper_width_mm' => self::DEFAULT_PAPER_WIDTH,
            'print_logo' => false,
            'is_default' => true,
        ];
    }
}
