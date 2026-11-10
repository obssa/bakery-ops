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
        // the dining tables the pads show (Orders, Phase 1, the admin's only then). Phase 6 follow-up (Ramin, 2026-11-09): the manager adds and
        // switches off tables too (Admin > Tables); the grant reaches a live database through 2026_11_14_100000_grant_tables_manage_to_manager alone (LATER_GRANTS, below).
        'tables.manage' => ['admin', 'manager'],
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
        // Step 4.3c (plan section 10.6): its own grant-only migration 2026_11_02_100100_grant_payments_verify_permission, because M2 has run on the
        // live database. payments.verify: verify and dispute payments against the bank's statement (later the reconciliation and the export),
        // see every payment of the branch (a cashier sees their own shift and the last 24 hours), delete anyone's unattached receipt photo.
        'payments.verify' => ['admin', 'manager'],
        // Step 4.4b (plan section 10.6): its own grant-only migration 2026_11_05_100100_grant_analytics_manage_permission (the plan named it for M2,
        // which had run on the live database without it). analytics.manage: the on-demand rebuild of the summary tables (later the weekly
        // summary's test send); configuration-like, so the admin's only.
        'analytics.manage' => ['admin'],
        // Step 4.5a (plan section 10.6): its own grant-only migration 2026_11_08_100200_grant_audit_export_permission (M2 has run on the live
        // database without it). audit.export: GET /audit-log/export, the whole log as a file; audit.view (above) reads it on the screen.
        'audit.export' => ['admin'],
        // Phase 5 (step 5.1a; plan sections 3 M2a and 10.6). They reach a live database through the grant-only migration
        // 2026_11_11_100200_grant_phase_5_device_permissions. devices.manage: GET and POST /devices, rename and revoke (until Phase 5 under
        // people.manage, which the admin keeps); devices.self: GET /devices/this, every role's "is this tablet enrolled?".
        'devices.manage' => ['admin'],
        'devices.self' => self::EVERYONE,
        // Phase 5 (step 5.3a; plan sections 3 M2b, 4.3 and 10.6). They reach a live database through the grant-only migration
        // 2026_11_11_100400_grant_phase_5_print_permissions. print.create: POST /orders/{order}/print; print.view: GET /print-jobs and
        // GET /orders/{order}/receipt.escpos. The print bridge's own routes are authorised by its device token, not by a permission.
        'print.create' => ['admin', 'manager', 'cashier'],
        'print.view' => ['admin', 'manager', 'cashier'],
        // Phase 5 (step 5.5c; plan sections 3 M2c, 4.4 and 10.6), through the grant-only migration
        // 2026_11_11_100800_grant_phase_5_ops_permissions. system.view: the System page and GET /system/status (the host's health).
        'system.view' => ['admin'],
        // Phase 6 follow-up (the bakery's own brand, Ramin 2026-11-10), through the grant-only migration
        // 2026_11_15_100000_grant_branding_permission. branding.manage: PUT /brand and POST and DELETE /brand/logo, the business name and logo
        // the app shows (every signed-in person reads them in GET /me).
        'branding.manage' => ['admin'],
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
     * The default grants that joined the matrix after their permission had reached the live database: permission => role => the
     * timestamp of the grant-only migration that brings that one grant there. An already-run grant migration must stay a faithful record
     * of what it did, so a grant migration made before that timestamp never makes the grant (see permissionsFor's $asOf); RolesSeeder,
     * a fresh install's role map, makes it with the rest.
     *
     * @var array<string, array<string, string>>
     */
    private const array LATER_GRANTS = [
        // Phase 6 follow-up (Admin > Tables; Ramin, 2026-11-09): the manager manages the dining tables as the admin does
        'tables.manage' => ['manager' => '2026_11_14_100000'],
    ];

    /**
     * The permissions a role holds by default. `$asOf`, a grant migration's timestamp (YYYY_MM_DD_HHMMSS), leaves out a later grant
     * (LATER_GRANTS) whose own migration comes after it: the matrix as that migration knew it. Null is today's matrix (RolesSeeder, Access).
     *
     * @return list<string>
     */
    public static function permissionsFor(string $role, ?string $asOf = null): array
    {
        if (! self::isRole($role)) {
            return [];
        }

        return array_keys(array_filter(
            self::MATRIX,
            static function (array $roles, string $permission) use ($role, $asOf): bool {
                if (! in_array($role, $roles, true)) {
                    return false;
                }
                $since = self::LATER_GRANTS[$permission][$role] ?? null;

                return $asOf === null || $since === null || strcmp($since, $asOf) <= 0;
            },
            ARRAY_FILTER_USE_BOTH,
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
