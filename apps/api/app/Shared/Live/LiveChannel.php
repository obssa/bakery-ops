<?php

declare(strict_types=1);

namespace App\Shared\Live;

use InvalidArgumentException;

/**
 * One live channel of the registry (Channels): which pattern it is and the id it names. name() is the bare name the
 * broadcast companions and Laravel use ("station.5"); subscriptionName() is what a transport asks to join
 * ("private-station.5"): every live channel is private.
 */
final readonly class LiveChannel
{
    public function __construct(public string $pattern, public int $id)
    {
        if (! in_array($pattern, Channels::patterns(), true)) {
            throw new InvalidArgumentException("Not a live channel pattern: {$pattern}");
        }
        if ($id < 1) {
            throw new InvalidArgumentException("A live channel names a positive id, got {$id}");
        }
    }

    public function name(): string
    {
        return (string) preg_replace('/\{[a-z]+\}/', (string) $this->id, $this->pattern, 1);
    }

    public function subscriptionName(): string
    {
        return Channels::PRIVATE_PREFIX.$this->name();
    }
}
