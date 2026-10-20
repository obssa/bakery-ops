<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Shared\Models\Branch;
use Illuminate\Database\Eloquent\Model;

/**
 * For the Catalog models GET /menu is computed from: every save and every delete (soft deletes included) adds one to
 * the branch's catalog_version, so the menu's ETag changes for every catalog write, however many land in one second
 * (updated_at only has whole seconds). One atomic `update ... set catalog_version = catalog_version + 1` on the
 * branch's primary key, inside the writer's own transaction: it rolls back with the write it counts. A write through the
 * query builder instead of the model is not counted, so Catalog writes go through its models.
 */
trait BumpsCatalogVersion
{
    public static function bootBumpsCatalogVersion(): void
    {
        $bump = static function (Model $model): void {
            $branchId = $model->getAttribute('branch_id');
            if (is_int($branchId)) {
                Branch::query()->whereKey($branchId)->toBase()->increment('catalog_version');
            }
        };
        static::saved($bump);
        static::deleted($bump);
    }
}
