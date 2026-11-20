<?php

declare(strict_types=1);

namespace App\Shared\StateMachine;

use App\Shared\DomainRefusal;

/**
 * What an action's own rules say about a record as it stands, before anything is written (ADR 0032). One method of the action computes
 * it, and both the action and the record's `actions` list read that one method, so the list a screen gets can never offer what the
 * action refuses:
 *   - allowed: the action may write now. The record lists the action.
 *   - repeat: the record already is what the action makes it (the same baker accepted it, the payment is verified); the action answers
 *     it as it stands and writes nothing. The record does not list the action: there is nothing to do.
 *   - refused: a rule says no, with the refusal the action throws (a 403 or a 409 with its message_key). Not listed.
 */
final class Verdict
{
    private const ALLOWED = 'allowed';

    private const REPEAT = 'repeat';

    private const REFUSED = 'refused';

    private function __construct(
        private readonly string $kind,
        private readonly ?DomainRefusal $refusal = null,
    ) {}

    public static function allowed(): self
    {
        return new self(self::ALLOWED);
    }

    public static function repeat(): self
    {
        return new self(self::REPEAT);
    }

    public static function refused(DomainRefusal $refusal): self
    {
        return new self(self::REFUSED, $refusal);
    }

    /** Allowed when the condition holds, else refused with the refusal the action throws. */
    public static function unless(bool $refuse, DomainRefusal $refusal): self
    {
        return $refuse ? self::refused($refusal) : self::allowed();
    }

    public function isAllowed(): bool
    {
        return $this->kind === self::ALLOWED;
    }

    public function isRepeat(): bool
    {
        return $this->kind === self::REPEAT;
    }

    /**
     * The action's own use: false when it may write now, true for a repeat (it answers the record as it stands), and the refusal thrown.
     *
     * @throws DomainRefusal
     */
    public function settled(): bool
    {
        if ($this->refusal !== null) {
            throw $this->refusal;
        }

        return $this->kind === self::REPEAT;
    }

    /**
     * The action's own use when a repeat is no answer of its own (the action has no "already done" case): throws the refusal, nothing else.
     *
     * @throws DomainRefusal
     */
    public function orThrow(): void
    {
        if ($this->refusal !== null) {
            throw $this->refusal;
        }
    }

    /** Then the next rule, when this one allows. */
    public function then(callable $next): self
    {
        if (! $this->isAllowed()) {
            return $this;
        }
        $verdict = $next();

        return $verdict instanceof self ? $verdict : self::allowed();
    }
}
