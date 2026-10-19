<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Shared\StateMachine\StateMachine;

/**
 * A small machine used to prove the StateMachine trait:
 * open <-> closed -> locked -> closed, locked -> demolished (terminal).
 */
enum DoorStatus: string
{
    use StateMachine;

    case Open = 'open';
    case Closed = 'closed';
    case Locked = 'locked';
    case Demolished = 'demolished';

    /** @return array<string, list<self>> */
    public static function transitions(): array
    {
        return [
            self::Open->value => [self::Closed],
            self::Closed->value => [self::Open, self::Locked],
            self::Locked->value => [self::Closed, self::Demolished],
            self::Demolished->value => [],
        ];
    }
}
