<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Ramsey\Uuid\Uuid;

/**
 * The deterministic client UUIDs of the Phase 4 history (step 4.6a): a UUIDv5 of the branch and a natural key such as
 * `demo-pay:{date}:{n}`, so a second run finds every row it wrote and writes nothing.
 */
final readonly class DemoIds
{
    public function __construct(private int $branchId) {}

    public function uuid(string $part): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "https://bakery-ops.orcamind.dev/demo-seed/{$this->branchId}/phase4/{$part}")->toString();
    }
}
