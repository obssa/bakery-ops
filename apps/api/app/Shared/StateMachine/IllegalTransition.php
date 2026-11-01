<?php

declare(strict_types=1);

namespace App\Shared\StateMachine;

use App\Shared\DomainRefusal;
use BackedEnum;
use DomainException;

/**
 * A status change that the machine's transitions() table does not list. Rendered as a bilingual 409 on API
 * routes (bootstrap/app.php). A refusal, not a failure (step 5.5a): never reported, logged once at info.
 */
final class IllegalTransition extends DomainException implements DomainRefusal
{
    private function __construct(
        public readonly string $machine,
        public readonly string $from,
        public readonly string $to,
    ) {
        parent::__construct(sprintf('%s cannot go from "%s" to "%s".', $machine, $from, $to));
    }

    public static function between(BackedEnum $from, BackedEnum $to): self
    {
        return new self($from::class, (string) $from->value, (string) $to->value);
    }

    public function messageKey(): string
    {
        return 'state_machine.illegal_transition';
    }

    /** The 409 bootstrap/app.php answers it with: a conflict with the current state. */
    public function httpStatus(): int
    {
        return 409;
    }
}
