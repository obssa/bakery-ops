<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A supplier as the purchases screens read it. The name is a proper noun, shown as typed.
 *
 * @mixin Supplier
 */
final class SupplierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Supplier $supplier */
        $supplier = $this->resource;

        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'phone' => $supplier->phone,
            'tin' => $supplier->tin,
            'notes' => $supplier->notes,
            'is_active' => $supplier->is_active,
        ];
    }
}
