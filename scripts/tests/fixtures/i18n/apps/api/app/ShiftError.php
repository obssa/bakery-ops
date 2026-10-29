<?php

// The i18n scripts' fixture code: raises two of the three shift messages and reads the currency; shifts.shift.gone is never read.
final class ShiftError
{
    public static function alreadyOpen(): self
    {
        return new self('shifts.shift.already_open');
    }

    public static function owner(): self
    {
        return new self("shifts.shift.owner");
    }

    public static function label(string $key): string
    {
        return trans('shifts.currency', ['key' => $key]);
    }
}
