<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Resources;

use App\Modules\Notifications\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One inbox line as the bell reads it (phase-2 plan section 4, "Resources"): title and body already in the caller's locale
 * (Recipients::localeOf: am or en), plus all four texts so the client can switch language without asking again, the event's
 * payload (ids and decimal strings), the path it opens and when it was read.
 *
 * @mixin Notification
 */
final class NotificationResource extends JsonResource
{
    public function __construct(Notification $resource, private readonly string $locale)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Notification $line */
        $line = $this->resource;
        $amharic = $this->locale === 'am';

        return [
            'id' => $line->id,
            'type' => $line->type,
            'severity' => $line->severity,
            'title' => $amharic ? $line->title_am : $line->title_en,
            'body' => $amharic ? $line->body_am : $line->body_en,
            'title_en' => $line->title_en,
            'title_am' => $line->title_am,
            'body_en' => $line->body_en,
            'body_am' => $line->body_am,
            'payload' => $line->payload,
            'deep_link' => $line->deep_link,
            'read_at' => $line->read_at?->toIso8601String(),
            'created_at' => $line->created_at->toIso8601String(),
        ];
    }
}
