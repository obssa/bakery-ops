<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Models\DiningTable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A dining table as the pad and the admin see it: its stored fields. The derived state (free, ordering, waiting,
 * served, bill) is added by the read models of step 1.5.
 *
 * @mixin DiningTable
 */
final class DiningTableResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DiningTable $table */
        $table = $this->resource;

        return [
            'id' => $table->id,
            'code' => $table->code,
            'name_en' => $table->name_en,
            'name_am' => $table->name_am,
            'seats' => $table->seats,
            'sort' => $table->sort,
            'is_active' => $table->is_active,
        ];
    }
}
