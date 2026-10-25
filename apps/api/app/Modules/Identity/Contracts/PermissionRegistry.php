<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/**
 * The section 7 permission matrix as data (workflows-and-screens.md). Permissions are dotted `<area>.<verb>` names;
 * each row lists the roles that hold it by default, admin always. RolesSeeder writes this into spatie's tables;
 * admins may then grant single permissions per user (direct permissions). Plain PHP so unit tests and seeders can
 * read it without the container.
 *
 * Matrix rows that are "own station" are the permission plus a station assignment (Access::can with a station);
 * "if flagged" is the is_overseer flag on the assignment (Stations::isOverseer); "request only" is the *.request /
 * *.void_request permission without the confirming one. `stations.all` lets admins and managers act at any station.
 */
final class PermissionRegistry
{
    public const array ROLES = ['admin', 'manager', 'cashier', 'waiter', 'chef', 'barista', 'cake_baker', 'bread_baker'];

    private const array EVERYONE = self::ROLES;

    private const array FLOOR = ['admin', 'manager', 'cashier', 'chef', 'barista', 'cake_baker', 'bread_baker'];

    /** @var array<string, list<string>> permission => roles holding it by default */
    private const array MATRIX = [
        'catalog.manage' => ['admin'],
        'catalog.view' => self::EVERYONE,
        'people.manage' => ['admin'],
        'inventory.view' => self::FLOOR,
        'inventory.adjust' => ['admin'],
        // configuring stock locations (Inventory, step 0.6): configuration, admin only like catalog.manage
        'inventory.manage' => ['admin'],
        'purchases.record' => ['admin', 'manager'],
        'orders.create' => ['admin', 'manager', 'cashier', 'waiter'],
        'orders.accept' => ['admin', 'manager', 'chef', 'barista'],
        'orders.void' => ['admin', 'manager'],
        'orders.void_request' => ['admin', 'manager', 'cashier'],
        // the dining tables the pads show (Orders, Phase 1): configuration, admin only like catalog.manage
        'tables.manage' => ['admin'],
        'payments.take' => ['admin', 'manager', 'cashier'],
        'payments.void' => ['admin', 'manager'],
        'production.request' => ['admin', 'manager', 'cashier', 'chef', 'barista'],
        'production.confirm' => ['admin', 'manager'],
        'production.fulfil' => ['admin', 'cake_baker', 'bread_baker'],
        'transfers.request' => self::FLOOR,
        'transfers.confirm' => ['admin', 'manager'],
        'shifts.count' => self::FLOOR,
        'shifts.cosign' => ['admin', 'manager'],
        'shifts.approve' => ['admin', 'manager'],
        'shifts.unlock' => ['admin'],
        'channels.manage' => ['admin'],
        'analytics.view' => ['admin', 'manager'],
        'analytics.view_own' => self::EVERYONE,
        'audit.view' => ['admin', 'manager'],
        'stations.all' => ['admin', 'manager'],
        // Phase 2 (step 2.1; plan section 10.5). They reach a live database through the grant-only migration
        // 2026_10_22_100100_grant_phase_2_permissions, because a deploy never runs seeders.
        'production.view' => self::FLOOR,
        'production.plan' => ['admin', 'manager'],
        'notifications.view' => self::EVERYONE,
        'costs.view' => ['admin', 'manager'],
        // Phase 3 (step 3.1a; plan section 10.6). They reach a live database through the grant-only migration
        // 2026_10_24_100100_grant_phase_3_permissions. shifts.count, .cosign, .approve and .unlock are older (above).
        'shifts.view' => self::FLOOR,
        'shifts.manage' => ['admin'],
        'shifts.replay' => ['admin', 'manager'],
        // Phase 4 (step 4.2b; plan section 10.6). They reach a live database through the grant-only migration
        // 2026_10_30_100100_grant_phase_4_permissions. files.upload: receipt photos; payments.view: payments, receipts and a photo's signed URL.
        'files.upload' => ['admin', 'manager', 'cashier'],
        'payments.view' => ['admin', 'manager', 'cashier'],
    ];

    /**
     * @return list<string>
     */
    public static function permissions(): array
    {
        return array_keys(self::MATRIX);
    }

    /**
     * @return list<string>
     */
    public static function rolesFor(string $permission): array
    {
        return self::MATRIX[$permission] ?? [];
    }

    /**
     * @return list<string>
     */
    public static function permissionsFor(string $role): array
    {
        if (! self::isRole($role)) {
            return [];
        }

        return array_keys(array_filter(
            self::MATRIX,
            static fn (array $roles): bool => in_array($role, $roles, true),
        ));
    }

    public static function isPermission(string $name): bool
    {
        return array_key_exists($name, self::MATRIX);
    }

    public static function isRole(string $name): bool
    {
        return in_array($name, self::ROLES, true);
    }
}
