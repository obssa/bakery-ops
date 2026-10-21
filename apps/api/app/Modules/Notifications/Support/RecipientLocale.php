<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Identity\Contracts\Recipients;

/**
 * The language to write to a person outside the app, and whether they can still be written to: Identity's Recipients answers
 * only for a user who is active in the branch being served, so a person who left since the line was written gets null here and
 * nothing is sent. Resolved per use (Recipients carries the scoped CurrentBranch).
 */
final class RecipientLocale
{
    public function __construct(private readonly Recipients $recipients) {}

    /** 'en' or 'am', or null when the person is not an active user of the current branch. */
    public function of(int $userId): ?string
    {
        $user = $this->recipients->byIds([$userId])->first();

        return $user === null ? null : $this->recipients->localeOf($user);
    }
}
