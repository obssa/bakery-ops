<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/**
 * The print bridge signed in on a request (step 5.3a; Bridges::current): its device id (the device_id of its writes), its branch and its
 * name. Never its token or the token's hash. Plain values, no model.
 */
final readonly class BridgeDevice
{
    public function __construct(
        public int $id,
        public int $branchId,
        public string $name,
    ) {}
}
