<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Handover;

use App\Modules\Shifts\Models\HandoverNote;

/** What writing a handover note answers: the note, and whether this call wrote it (false: the client_uuid had been used). */
final readonly class NoteOutcome
{
    public function __construct(public HandoverNote $note, public bool $created) {}
}
