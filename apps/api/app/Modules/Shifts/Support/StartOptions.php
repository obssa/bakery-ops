<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Support;

/**
 * What a start asks for (POST /stations/{station}/shifts/start): the client UUID of the shift it may create, a scheduled instance
 * by id, a template by id, `carry` (open without counting: every start in step 3.2 is carried, there are no counts yet), and `force`
 * (outside the window; the admin's).
 */
final readonly class StartOptions
{
    public function __construct(
        public string $clientUuid,
        public ?int $shiftId = null,
        public ?int $templateId = null,
        public bool $carry = false,
        public bool $force = false,
    ) {}
}
