<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Drivers;

use App\Modules\Notifications\Models\Notification;

/** What a driver puts on the wire for one inbox line: its title and body in the recipient's language, and where it opens. */
final readonly class Message
{
    public function __construct(
        public string $title,
        public string $body,
        public string $url,
        public string $type,
        public string $severity,
        public string $locale,
    ) {}

    public static function of(Notification $line, string $locale): self
    {
        $amharic = $locale === 'am';

        return new self(
            title: $amharic ? $line->title_am : $line->title_en,
            body: $amharic ? $line->body_am : $line->body_en,
            url: $line->deep_link,
            type: $line->type,
            severity: $line->severity,
            locale: $amharic ? 'am' : 'en',
        );
    }
}
