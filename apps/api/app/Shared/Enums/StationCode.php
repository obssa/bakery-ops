<?php

declare(strict_types=1);

namespace App\Shared\Enums;

use App\Shared\I18n\Bilingual;

/**
 * The six station kinds (architecture.md domain model). The stations table enforces the same list with a
 * check constraint; tests/Modules/Shared/SharedSchemaTest.php keeps the two identical.
 */
enum StationCode: string
{
    case Till = 'till';
    case Kitchen = 'kitchen';
    case Bar = 'bar';
    case CakeRoom = 'cake_room';
    case Bakery = 'bakery';
    case Store = 'store';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $code): string => $code->value, self::cases());
    }

    /** The default bilingual name a seeder or admin screen starts from. */
    public function defaultName(): Bilingual
    {
        return match ($this) {
            self::Till => Bilingual::of('Till', 'ካሻ'),
            self::Kitchen => Bilingual::of('Kitchen', 'ኩሽና'),
            self::Bar => Bilingual::of('Bar', 'ባር'),
            self::CakeRoom => Bilingual::of('Cake room', 'የኬክ ክፍል'),
            self::Bakery => Bilingual::of('Bakery', 'ዳቦ ቤት'),
            self::Store => Bilingual::of('Store', 'መጋዘን'),
        };
    }
}
