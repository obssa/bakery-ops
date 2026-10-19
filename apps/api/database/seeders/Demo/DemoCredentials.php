<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

/**
 * The credentials of the demo accounts, so that nothing secret is committed: every demo account's password is
 * BAKERY_DEMO_PASSWORD (config bakery.demo.password) and its short code is derived from that password. In local and
 * testing (the dev stack, Pest, CI's compose stack) an unset password falls back to LOCAL_PASSWORD, documented here and
 * public; anywhere else the password is required and the documented one is refused (DemoSeedRefused).
 */
final class DemoCredentials
{
    /** The documented password of the demo accounts in local and testing environments only. */
    public const string LOCAL_PASSWORD = 'demo-bakery-local';

    private const int MIN_LENGTH = 8;

    private const int CODE_DIGITS = 4;

    /**
     * The password every demo account gets, checked before anything is written.
     *
     * @throws DemoSeedRefused when it is missing or unsafe for this environment
     */
    public static function password(): string
    {
        $configured = config('bakery.demo.password');
        $configured = is_string($configured) ? $configured : '';
        $environment = (string) app()->environment();
        $documentedAllowed = app()->environment('local', 'testing');

        if ($configured === '') {
            if (! $documentedAllowed) {
                throw DemoSeedRefused::passwordMissing($environment);
            }

            return self::LOCAL_PASSWORD;
        }
        if (strlen($configured) < self::MIN_LENGTH) {
            throw DemoSeedRefused::passwordTooShort();
        }
        if (! $documentedAllowed && $configured === self::LOCAL_PASSWORD) {
            throw DemoSeedRefused::documentedPassword($environment);
        }

        return $configured;
    }

    /**
     * A short code for each phone, derived from the password (HMAC-SHA256), 4 digits like the codes Identity generates,
     * unique among the phones given: the same password and phones always give the same codes, so a run on another
     * machine with the same BAKERY_DEMO_PASSWORD reproduces them, and nobody can compute them without it.
     *
     * @param  list<string>  $phones
     * @return array<string, string> phone => code
     */
    public static function shortCodes(array $phones, string $password): array
    {
        $codes = [];
        $taken = [];
        foreach ($phones as $phone) {
            for ($attempt = 0; ; $attempt++) {
                $code = self::derive($phone, $attempt, $password);
                if (! isset($taken[$code])) {
                    break;
                }
            }
            $taken[$code] = true;
            $codes[$phone] = $code;
        }

        return $codes;
    }

    /** The next candidate for $phone after $code is taken by someone outside the demo set (attempts beyond the set's own). */
    public static function alternative(string $phone, int $attempt, string $password): string
    {
        return self::derive($phone, 1000 + $attempt, $password);
    }

    private static function derive(string $phone, int $attempt, string $password): string
    {
        $hash = hash_hmac('sha256', "bakery-ops demo short code|{$phone}|{$attempt}", $password);
        $number = ((int) hexdec(substr($hash, 0, 7))) % (10 ** self::CODE_DIGITS);

        return str_pad((string) $number, self::CODE_DIGITS, '0', STR_PAD_LEFT);
    }
}
