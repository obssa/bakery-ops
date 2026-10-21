<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Shared\I18n\Bilingual;

/** The title and the body of one notification, each in English and Amharic (NotificationText::render). */
final readonly class RenderedText
{
    public function __construct(public Bilingual $title, public Bilingual $body) {}
}
