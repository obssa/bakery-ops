<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Handover;

use App\Modules\Identity\Contracts\People;
use App\Modules\Shifts\Models\HandoverNote;

/**
 * The Note of the API (phase-3 plan section 4, "Resources"): {id, client_uuid, shift_id, from {id, name}, to {id, name} or null, body,
 * created_at}. Names come from Identity's People in one query however many notes are shown. Times are UTC ISO-8601. Resolved per use.
 */
final class NotePresenter
{
    public function __construct(private readonly People $people) {}

    /**
     * @param  iterable<HandoverNote>  $notes
     * @return list<array<string, mixed>>
     */
    public function many(iterable $notes): array
    {
        $list = [];
        $ids = [];
        foreach ($notes as $note) {
            $list[] = $note;
            $ids[] = $note->from_user_id;
            if ($note->to_user_id !== null) {
                $ids[] = $note->to_user_id;
            }
        }
        $names = $this->people->names($ids);
        $person = static fn (?int $id): ?array => $id === null ? null : ['id' => $id, 'name' => $names[$id] ?? ''];

        return array_map(static fn (HandoverNote $note): array => [
            'id' => $note->id,
            'client_uuid' => $note->client_uuid,
            'shift_id' => $note->shift_instance_id,
            'from' => $person($note->from_user_id),
            'to' => $person($note->to_user_id),
            'body' => $note->body,
            'created_at' => $note->created_at->toIso8601String(),
        ], $list);
    }

    /**
     * @return array<string, mixed>
     */
    public function one(HandoverNote $note): array
    {
        return $this->many([$note])[0];
    }
}
