<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/** The binding until Identity binds ActorNames (and in any environment without it): nobody has a name. */
final class NoActorNames implements ActorNames
{
    public function names(array $ids): array
    {
        return [];
    }
}
