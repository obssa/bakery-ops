<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Notifications\Exceptions\MissingPayloadKey;
use App\Modules\Notifications\Exceptions\UnknownNotificationType;
use App\Shared\I18n\Bilingual;
use LogicException;

/**
 * The title and body of a notification in English and Amharic, written from the event payload alone (plan section 5): it
 * never reads another module's models, so a text can only say what its event carried (names in both languages, numbers as
 * decimal strings). Both languages are stored in the inbox row; the reader's locale picks one later.
 *
 * The templates are lang/{en,am}/notifications.php, text.<type>.title|body (a variant key where the wording differs:
 * body_no_table for a counter order, confirmed|rejected for a transfer decision, body_stock_out for a request a stock-out made). A placeholder without a value is a bug
 * (LogicException), never a line with ":item" in it, and a payload without what the text needs is MissingPayloadKey.
 * Quantities are written without trailing zeros by string handling, never through a float (ADR 0004).
 */
final class NotificationText
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function render(string $type, array $payload): RenderedText
    {
        if (! NotificationTypes::has($type)) {
            throw UnknownNotificationType::named($type);
        }
        [$base, $bodyKey] = $this->keys($type, $payload);

        $title = [];
        $body = [];
        foreach (['en', 'am'] as $locale) {
            $values = $this->values($type, $payload, $locale);
            $title[$locale] = $this->fill($this->template("{$base}.title", $locale), $values);
            $body[$locale] = $this->fill($this->template("{$base}.{$bodyKey}", $locale), $values);
        }

        return new RenderedText(new Bilingual($title['en'], $title['am']), new Bilingual($body['en'], $body['am']));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string} the lang prefix of the type's texts and the key of its body
     */
    private function keys(string $type, array $payload): array
    {
        return match ($type) {
            'line_ready', 'line_rejected' => ["text.{$type}", $this->optional($type, $payload, 'table_code') === null ? 'body_no_table' : 'body'],
            'production_requested' => ['text.production_requested', $this->str($type, $payload, 'reason') === 'stock_out' ? 'body_stock_out' : 'body'],
            'transfer_decided' => ['text.transfer_decided.'.($this->str($type, $payload, 'status') === 'rejected' ? 'rejected' : 'confirmed'), 'body'],
            default => ["text.{$type}", 'body'],
        };
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, string>
     */
    private function values(string $type, array $p, string $locale): array
    {
        $in = static fn (string $name): string => $name.'_'.$locale;

        return match ($type) {
            'line_ready' => [
                'item' => $this->str($type, $p, $in('item_name')),
                'order_no' => (string) $this->int($type, $p, 'order_no'),
                'table' => $this->optional($type, $p, 'table_code') ?? '',
            ],
            'line_rejected' => [
                'item' => $this->str($type, $p, $in('item_name')),
                'order_no' => (string) $this->int($type, $p, 'order_no'),
                'table' => $this->optional($type, $p, 'table_code') ?? '',
                'reason' => trim($this->str($type, $p, $in('reason_message')).' '.($this->optional($type, $p, 'note') ?? '')),
            ],
            'stock_out' => [
                'item' => $this->str($type, $p, $in('item_name')),
                'place' => $this->str($type, $p, $in('location_name')),
            ],
            'stock_low' => [
                'item' => $this->str($type, $p, $in('item_name')),
                'place' => $this->str($type, $p, $in('location_name')),
                'available' => $this->withUnit($this->quantity($this->str($type, $p, 'available')), $this->optional($type, $p, 'unit')),
                'min' => $this->withUnit($this->quantity($this->str($type, $p, 'min_qty')), $this->optional($type, $p, 'unit')),
                'reorder' => $this->withUnit($this->quantity($this->str($type, $p, 'reorder_qty')), $this->optional($type, $p, 'unit')),
            ],
            'transfer_requested', 'transfer_decided' => [
                'item' => $this->str($type, $p, $in('item_name')),
                'qty' => $this->quantity($this->str($type, $p, 'qty')),
                'unit' => $this->str($type, $p, 'unit'),
                'from' => $this->str($type, $p, $in('from_location_name')),
                'to' => $this->str($type, $p, $in('to_location_name')),
                'reason' => $type === 'transfer_decided' ? ($this->optional($type, $p, 'reject_reason') ?? '-') : '',
            ],
            'production_requested', 'production_assigned' => [
                'item' => $this->str($type, $p, $in('product_name')),
                'qty' => $this->quantity($this->str($type, $p, 'qty')),
                'unit' => $this->str($type, $p, 'unit'),
                'station' => $this->str($type, $p, $in('station_name')),
            ],
            'batch_pending_confirmation', 'batch_done' => [
                'item' => $this->str($type, $p, $in('product_name')),
                'produced' => $this->quantity($this->str($type, $p, 'produced_qty')),
                'waste' => $this->quantity($this->str($type, $p, 'waste_qty')),
                'unit' => $this->str($type, $p, 'unit'),
                'station' => $this->str($type, $p, $in('station_name')),
            ],
            default => throw UnknownNotificationType::named($type),
        };
    }

    /** 5.0000 -> 5, 2.5000 -> 2.5, 100 -> 100: strip zeros only after a decimal point, as a string. */
    private function quantity(string $qty): string
    {
        if (! str_contains($qty, '.')) {
            return $qty;
        }

        return rtrim(rtrim($qty, '0'), '.');
    }

    /** 4000 and g -> "4000 g"; no unit in the payload -> "4000". */
    private function withUnit(string $quantity, ?string $unit): string
    {
        return $unit === null || $unit === '' ? $quantity : $quantity.' '.$unit;
    }

    private function template(string $key, string $locale): string
    {
        $text = trans('notifications.'.$key, [], $locale);
        if (! is_string($text) || $text === 'notifications.'.$key) {
            throw new LogicException("lang/{$locale}/notifications.php has no '{$key}'.");
        }

        return $text;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function fill(string $template, array $values): string
    {
        return (string) preg_replace_callback('/:([a-z_]+)/', static function (array $match) use ($values, $template): string {
            return $values[$match[1]] ?? throw new LogicException("The text '{$template}' asks for :{$match[1]} and the payload has no value for it.");
        }, $template);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function str(string $type, array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : throw MissingPayloadKey::for($type, $key);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function optional(string $type, array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;
        if ($value === null || is_string($value)) {
            return $value;
        }

        throw MissingPayloadKey::for($type, $key);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function int(string $type, array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        return is_int($value) ? $value : throw MissingPayloadKey::for($type, $key);
    }
}
