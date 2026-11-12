<?php

declare(strict_types=1);

namespace App\Modules\Identity\Demo;

use App\Modules\Identity\Models\User;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\BranchScope;

/**
 * TEMPORARY (ADR 0025, Ramin 2026-11-12): the demo people the sign-in page offers as one-click buttons, one per role, so the owner can
 * look at every role's screens without typing a password. Only while bakery.demo.login is on, only in the demo branch (named
 * bakery.demo.branch and marked settings.demo = true by bakery:seed-demo), and only the accounts listed here, found by their made-up
 * phone numbers (Database\Seeders\Demo\DemoBakery::PEOPLE; DemoLoginTest keeps the two lists in step): the second waiter and the
 * shared pad's account are never offered, nor anyone the admin adds to the demo branch, nor anyone of another branch.
 *
 * Removal: unset BAKERY_DEMO_LOGIN on the server, then delete this class, DemoLoginController, its request, its two routes and the
 * web section (components/DemoLogins.tsx).
 */
final class DemoLogins
{
    /** @var array<string, string> role => phone, in the order the page shows them */
    public const array PEOPLE = [
        'admin' => '+251900000101',
        'manager' => '+251900000102',
        'cashier' => '+251900000103',
        'waiter' => '+251900000104',
        'chef' => '+251900000106',
        'barista' => '+251900000107',
        'cake_baker' => '+251900000108',
        'bread_baker' => '+251900000109',
    ];

    public function enabled(): bool
    {
        return config('bakery.demo.login') === true;
    }

    /**
     * The demo people that can be signed in now: active, still holding their role.
     *
     * @return list<array{role: string, name_en: string, name_am: string}>
     */
    public function people(): array
    {
        $people = [];
        foreach (array_keys(self::PEOPLE) as $role) {
            $user = $this->userFor($role);
            if ($user !== null) {
                [$en, $am] = self::names($user->name);
                $people[] = ['role' => $role, 'name_en' => $en, 'name_am' => $am];
            }
        }

        return $people;
    }

    /** The demo person of $role, or null: an unknown role, no demo branch, the account gone, switched off or no longer in that role. */
    public function userFor(string $role): ?User
    {
        $phone = self::PEOPLE[$role] ?? null;
        $branch = $phone === null ? null : $this->branch();
        if ($phone === null || $branch === null) {
            return null;
        }

        $user = User::query()->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branch->id)
            ->where('phone', $phone)
            ->first();

        return $user !== null && $user->is_active && $user->hasRole($role) ? $user : null;
    }

    private function branch(): ?Branch
    {
        $name = config('bakery.demo.branch');
        $branch = Branch::query()->where('name', is_string($name) && $name !== '' ? $name : 'Demo Bakery')->first();

        return $branch !== null && ($branch->settings['demo'] ?? false) === true ? $branch : null;
    }

    /**
     * users.name holds a demo person's name as "English (Amharic)" (DemoBakery::PEOPLE).
     *
     * @return array{0: string, 1: string}
     */
    private static function names(string $name): array
    {
        if (preg_match('/^(.+?)\s*\((.+)\)$/u', trim($name), $m) === 1) {
            return [trim($m[1]), trim($m[2])];
        }

        return [$name, $name];
    }
}
