<?php

declare(strict_types=1);

namespace App\Shared\Live;

/**
 * The registry of live channels (ADR 0009, step 1.2): the private channels a WebSocket transport would carry, named in
 * one place so the broadcast companions (Shared), the channel rules (Identity's ChannelRules) and the web client's
 * LiveFeed agree.
 *
 *   station.{station}        a station's tickets (the KDS)
 *   user.{user}              one user's own orders (the pad's ready badge)
 *   branch.{branch}.stock    a branch's stock (menu availability)
 *   branch.{branch}.orders   a branch's orders (the till queue, the admin live board)
 *
 * A transport subscribes with the `private-` prefix (Laravel Echo's Echo.private('station.5') asks for
 * `private-station.5`); companions name the bare channel and Laravel adds the prefix. Ids are positive integers
 * without leading zeros and at most 18 digits, so they are always an int. A name the registry does not know is no
 * channel at all: the subscription endpoint refuses it before any rule runs. Polling clients never use channels: the
 * cursor feeds are the source of truth, a channel only says "look again".
 */
final class Channels
{
    public const string STATION = 'station.{station}';

    public const string USER = 'user.{user}';

    public const string BRANCH_STOCK = 'branch.{branch}.stock';

    public const string BRANCH_ORDERS = 'branch.{branch}.orders';

    public const string PRIVATE_PREFIX = 'private-';

    /** pattern => the expression a name of it matches, with the id in group 1 */
    private const array MATCHERS = [
        self::STATION => '/\Astation\.([1-9][0-9]{0,17})\z/',
        self::USER => '/\Auser\.([1-9][0-9]{0,17})\z/',
        self::BRANCH_STOCK => '/\Abranch\.([1-9][0-9]{0,17})\.stock\z/',
        self::BRANCH_ORDERS => '/\Abranch\.([1-9][0-9]{0,17})\.orders\z/',
    ];

    public static function station(int $stationId): string
    {
        return (new LiveChannel(self::STATION, $stationId))->name();
    }

    public static function user(int $userId): string
    {
        return (new LiveChannel(self::USER, $userId))->name();
    }

    public static function branchStock(int $branchId): string
    {
        return (new LiveChannel(self::BRANCH_STOCK, $branchId))->name();
    }

    public static function branchOrders(int $branchId): string
    {
        return (new LiveChannel(self::BRANCH_ORDERS, $branchId))->name();
    }

    /**
     * @return list<string>
     */
    public static function patterns(): array
    {
        return array_keys(self::MATCHERS);
    }

    /** A bare channel name ("station.5") back to its pattern and id; null when the registry does not know it. */
    public static function parse(string $name): ?LiveChannel
    {
        foreach (self::MATCHERS as $pattern => $expression) {
            if (preg_match($expression, $name, $match) === 1) {
                return new LiveChannel($pattern, (int) $match[1]);
            }
        }

        return null;
    }

    /** A name as a transport asks to join it: `private-` plus a registered channel; public and presence names are none. */
    public static function parseSubscription(string $requested): ?LiveChannel
    {
        if (! str_starts_with($requested, self::PRIVATE_PREFIX)) {
            return null;
        }

        return self::parse(substr($requested, strlen(self::PRIVATE_PREFIX)));
    }
}
