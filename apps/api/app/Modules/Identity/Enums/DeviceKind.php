<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

/**
 * What a device is (phase-5 plan 3 M1, ADR 0017), in the order of the devices_kind_check CHECK: a tablet (the pad, the default, so every
 * row enrolled before Phase 5 is one), a till, a KDS screen, or a print bridge, the shop computer that pulls receipt jobs over HTTPS with
 * its own token (step 5.3a). Only a bridge carries a token; the devices_token_only_for_bridge CHECK says the same in the database.
 */
enum DeviceKind: string
{
    case Tablet = 'tablet';
    case Till = 'till';
    case Kds = 'kds';
    case Bridge = 'bridge';

    public static function default(): self
    {
        return self::Tablet;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }

    public function carriesToken(): bool
    {
        return $this === self::Bridge;
    }
}
