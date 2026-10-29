<?php

declare(strict_types=1);

namespace App\Modules\Production\Exceptions;

use App\Shared\DomainRefusal;
use App\Shared\Http\ApiError;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Every client-facing refusal the production request actions make, as a stable message_key (lang/<locale>/production.php,
 * rule 9) plus the HTTP status it maps to and what it is about (the request, the status, the product). Thrown inside an
 * AuditedAction it rolls the whole write back; Laravel renders it through render().
 */
final class ProductionError extends RuntimeException implements DomainRefusal
{
    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, array{en: string, am: string}|string>  $messageParams  values filled into the message's :placeholders, per language
     */
    private function __construct(
        public readonly string $messageKey,
        public readonly int $status,
        public readonly array $extra = [],
        public readonly array $messageParams = [],
    ) {
        parent::__construct($messageKey);
    }

    /** The person acting by short code does not hold the permission the logged-in account passed the route with, or does not exist. */
    public static function forbidden(): self
    {
        return new self('auth.forbidden', 403);
    }

    /** A due time or a priority other than normal is the planner's (production.plan). */
    public static function planRequired(): self
    {
        return new self('production.plan.required', 403);
    }

    /** The baker does not work at the station that makes the request, and does not work at every station. */
    public static function stationForbidden(): self
    {
        return new self('production.station.forbidden', 403);
    }

    /** Only a planner, or the person who asked, cancels a request. */
    public static function cancelForbidden(): self
    {
        return new self('production.cancel.forbidden', 403);
    }

    /** A manual request for a product that already has an open one: the open request is named. */
    public static function alreadyOpen(int $requestId): self
    {
        return new self('production.request.already_open', 409, ['request_id' => $requestId]);
    }

    /** The request is assigned to, or accepted by, somebody else. */
    public static function taken(): self
    {
        return new self('production.request.taken', 409);
    }

    /** A request whose batch is started or finished is finished or sent back, never cancelled. */
    public static function inProgress(string $status): self
    {
        return new self('production.request.in_progress', 409, ['status' => $status]);
    }

    /** Not a product that is kept in stock and made from an active recipe of this branch. */
    public static function notProducible(int $productId): self
    {
        return new self('production.product.not_producible', 422, ['product_item_id' => $productId]);
    }

    /** Nobody makes this product: it has no producing station. */
    public static function noStation(int $productId): self
    {
        return new self('production.product.no_station', 422, ['product_item_id' => $productId]);
    }

    /** The person named does not hold production.fulfil at the request's station (or is not an active person of this branch). */
    public static function assigneeInvalid(int $userId): self
    {
        return new self('production.assignee.invalid', 422, ['user_id' => $userId]);
    }

    /** The baker presses Start on a request nobody accepted yet. */
    public static function notAccepted(string $status): self
    {
        return new self('production.request.not_accepted', 409, ['status' => $status]);
    }

    /** The station or the product has no stock location, so a batch could not say where it takes from or puts to. */
    public static function locationsUnresolved(int $stationId, int $productId): self
    {
        return new self('production.locations.unresolved', 422, ['station_id' => $stationId, 'product_item_id' => $productId]);
    }

    /** Who finished a batch cannot sign it off (the database CHECK refuses it too, except in the write that finishes it). */
    public static function selfConfirm(): self
    {
        return new self('production.batch.self_confirm', 409);
    }

    /** Neither production.confirm nor the overseer flag at the batch's station. */
    public static function confirmForbidden(): self
    {
        return new self('production.confirm.forbidden', 403);
    }

    /**
     * One or more ingredients are not available (on hand minus reserved) at the consume place for what the batch would take: nothing
     * is written. The message names the first short ingredient, how much is short and where, in both languages.
     *
     * @param  list<array{ingredient: array{id: int, name_en: string, name_am: string, unit: string}, needed: string, available: string, short: string, location_id: int, location: array{id: int, name_en: string, name_am: string}|null}>  $shortfall
     */
    public static function ingredientShort(array $shortfall): self
    {
        $first = $shortfall[0];
        $place = $first['location'];
        $more = ['en' => '', 'am' => ''];
        if (count($shortfall) > 1) {
            $more = ['en' => (string) trans('production.batch.more_short', [], 'en'), 'am' => (string) trans('production.batch.more_short', [], 'am')];
        }

        return new self('production.batch.ingredient_short', 409, ['shortfall' => $shortfall], [
            'ingredient' => ['en' => $first['ingredient']['name_en'], 'am' => $first['ingredient']['name_am']],
            'short' => self::trimmed($first['short']),
            'unit' => $first['ingredient']['unit'],
            'place' => ['en' => $place['name_en'] ?? '', 'am' => $place['name_am'] ?? ''],
            'more' => $more,
        ]);
    }

    /** DomainRefusal (step 5.5a): the key the info line carries instead of a stack trace. */
    public function messageKey(): string
    {
        return $this->messageKey;
    }

    /** DomainRefusal (step 5.5a): a 4xx is a refusal and is not reported; a 5xx is a failure and is. */
    public function httpStatus(): int
    {
        return $this->status;
    }

    public function render(): JsonResponse
    {
        $body = ApiError::body($this->messageKey, $this->extra);
        if ($this->messageParams !== []) {
            foreach (['en', 'am'] as $locale) {
                $values = array_map(static fn (array|string $v): string => is_array($v) ? $v[$locale] : $v, $this->messageParams);
                $body['message_'.$locale] = (string) trans($this->messageKey, $values, $locale);
            }
        }

        return response()->json($body, $this->status);
    }

    /** 300.0000 -> 300, 0.5000 -> 0.5: zeros after the decimal point only, by string handling. */
    private static function trimmed(string $qty): string
    {
        return str_contains($qty, '.') ? rtrim(rtrim($qty, '0'), '.') : $qty;
    }
}
