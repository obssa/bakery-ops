<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Enums;

/**
 * Why a day was rebuilt (analytics_days.trigger, CHECK analytics_days_trigger_check; phase-4 plan section 5; ADR 0014): a shift that
 * locked (its days), the nightly run (yesterday and the day before), a person asking (the console or POST /analytics/rebuild), the
 * debounced touch of today after a payment (step 4.4b), the demo seeder.
 */
enum RebuildTrigger: string
{
    case ShiftLocked = 'shift_locked';
    case Nightly = 'nightly';
    case Manual = 'manual';
    case Touch = 'touch';
    case Seed = 'seed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $trigger): string => $trigger->value, self::cases());
    }
}
