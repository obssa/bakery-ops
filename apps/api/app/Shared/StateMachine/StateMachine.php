<?php

declare(strict_types=1);

namespace App\Shared\StateMachine;

/**
 * For backed enums that are status machines (orders, lines, shifts, counts, payments, transfers ...).
 * transitions() is the whole truth: every target not listed throws IllegalTransition, and
 * tests/Unit/StateMachineExhaustiveTest.php proves every from x to pair of every machine.
 *
 * @phpstan-require-implements \BackedEnum
 */
trait StateMachine
{
    /**
     * Allowed targets keyed by the source case value. One row per case; a terminal state has an empty list.
     *
     * @return array<string, list<self>>
     */
    abstract public static function transitions(): array;

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->nextStates(), true);
    }

    /**
     * @throws IllegalTransition
     */
    public function transitionTo(self $to): self
    {
        if (! $this->canTransitionTo($to)) {
            throw IllegalTransition::between($this, $to);
        }

        return $to;
    }

    /**
     * @return list<self>
     */
    public function nextStates(): array
    {
        return static::transitions()[(string) $this->value] ?? [];
    }

    public function isTerminal(): bool
    {
        return $this->nextStates() === [];
    }

    /**
     * Every from x to pair, listed or not: the table the exhaustive test walks.
     *
     * @return list<array{0: self, 1: self}>
     */
    public static function allPairs(): array
    {
        $pairs = [];
        foreach (static::cases() as $from) {
            foreach (static::cases() as $to) {
                $pairs[] = [$from, $to];
            }
        }

        return $pairs;
    }
}
