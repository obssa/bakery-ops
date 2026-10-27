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
            'count_submitted' => [
                'text.count_submitted.'.match ($this->str($type, $payload, 'status_after')) {
                    'awaiting_cosign', 'pending_approval' => $this->str($type, $payload, 'status_after'),
                    default => 'done',
                },
                $this->int($type, $payload, 'variance_lines') > 0 ? 'body_differ' : 'body_match',
            ],
            'variance_decided' => ['text.variance_decided.'.($this->str($type, $payload, 'decision') === 'rejected' ? 'rejected' : 'approved'), 'body'],
            'transfer_decided' => ['text.transfer_decided.'.($this->str($type, $payload, 'status') === 'rejected' ? 'rejected' : 'confirmed'), 'body'],
            'chain_broken' => ['text.chain_broken', 'body'],
            'weekly_summary' => ['text.weekly_summary', is_string($payload['pdf_url_en'] ?? null) ? 'body' : 'body_no_link'],
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
            'count_submitted' => [
                'station' => $this->str($type, $p, $in('station_name')),
                'place' => $this->str($type, $p, $in('location_name')),
                'type' => $this->template('count_type.'.$this->str($type, $p, 'type'), $locale),
                'lines' => (string) $this->int($type, $p, 'lines'),
                'varied' => (string) $this->int($type, $p, 'variance_lines'),
                'items' => $this->varianceItems($type, $p, $locale),
            ],
            'variance_decided' => [
                'station' => $this->str($type, $p, $in('station_name')),
                'place' => $this->str($type, $p, $in('location_name')),
                'type' => $this->template('count_type.'.$this->str($type, $p, 'type'), $locale),
                'adjusted' => (string) $this->int($type, $p, 'adjusted_lines'),
                'note' => $this->optional($type, $p, 'note') ?? '-',
            ],
            'chain_broken' => [
                'place' => $this->str($type, $p, $in('location_name')),
                'seq' => (string) $this->int($type, $p, 'first_bad_chain_seq'),
                'kind' => $this->template('chain_kind.'.$this->str($type, $p, 'kind'), $locale),
            ],
            'payment_disputed' => [
                'order_no' => (string) $this->int($type, $p, 'order_no'),
                'amount' => $this->str($type, $p, 'amount'),
                'channel' => $this->str($type, $p, $in('channel_name')),
                'reason' => $this->str($type, $p, 'reason'),
            ],
            'weekly_summary' => [
                'from' => $this->str($type, $p, 'week_start'),
                'to' => $this->str($type, $p, 'week_end'),
                'revenue' => $this->str($type, $p, 'revenue'),
                'revenue_before' => $this->str($type, $p, 'revenue_before'),
                'revenue_change' => $this->change($type, $p, 'revenue_change_pct', $locale),
                'orders' => (string) $this->int($type, $p, 'orders_count'),
                'orders_before' => (string) $this->int($type, $p, 'orders_before'),
                'best' => $this->margins($type, $p, 'best_items', $locale),
                'worst' => $this->margins($type, $p, 'worst_items', $locale),
                'channels' => $this->shares($type, $p, $locale),
                'refunds' => $this->str($type, $p, 'refund_total'),
                'voids' => $this->str($type, $p, 'voided_total'),
                'variance_lines' => (string) $this->int($type, $p, 'variance_line_count'),
                'cash_variance' => $this->str($type, $p, 'cash_variance_total'),
                'stockouts' => $this->stockouts($type, $p, $locale),
                'pdf' => $this->optional($type, $p, 'pdf_url_'.$locale) ?? $this->optional($type, $p, 'pdf_url_en') ?? '',
            ],
            default => throw UnknownNotificationType::named($type),
        };
    }

    /**
     * The variance items of a count as one phrase ("Flour -500 g, Sugar +300 g", then "and 3 more" when the event carried fewer
     * than the count has), in the reader's language. Signed decimal strings without trailing zeros; no float.
     *
     * @param  array<string, mixed>  $p
     */
    private function varianceItems(string $type, array $p, string $locale): string
    {
        $items = $p['items'] ?? [];
        if (! is_array($items)) {
            throw MissingPayloadKey::for($type, 'items');
        }
        $parts = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw MissingPayloadKey::for($type, 'items');
            }
            $name = $item['item_name_'.$locale] ?? null;
            $variance = $item['variance_qty'] ?? null;
            if (! is_string($name) || ! is_string($variance)) {
                throw MissingPayloadKey::for($type, 'items');
            }
            $unit = $item['unit'] ?? '';
            $signed = $this->quantity($variance);
            $parts[] = $name.' '.($signed !== '' && $signed[0] !== '-' ? '+' : '').$this->withUnit($signed, is_string($unit) ? $unit : null);
        }
        $more = $this->int($type, $p, 'variance_lines') - count($parts);
        if ($more > 0) {
            $parts[] = str_replace(':more', (string) $more, $this->template('count_more', $locale));
        }

        return implode(', ', $parts);
    }

    /**
     * A change in percent, signed ("+25.00%", "-3.50%"), or the words for "nothing to compare with" when the week before had nothing.
     *
     * @param  array<string, mixed>  $p
     */
    private function change(string $type, array $p, string $key, string $locale): string
    {
        $pct = $this->optional($type, $p, $key);
        if ($pct === null) {
            return $this->template('weekly.no_change', $locale);
        }

        return ($pct !== '' && $pct[0] === '-' ? $pct : '+'.$pct).'%';
    }

    /**
     * The products of a margin list, in the reader's language: "Croissant 134.50 (74.72%), Bread 10.25 (33.88%)", or "none".
     *
     * @param  array<string, mixed>  $p
     */
    private function margins(string $type, array $p, string $key, string $locale): string
    {
        $parts = [];
        foreach ($this->rows($type, $p, $key) as $item) {
            $margin = $item['margin_total'] ?? null;
            $pct = $item['margin_pct'] ?? null;
            if (! is_string($margin)) {
                throw MissingPayloadKey::for($type, $key);
            }
            $parts[] = $this->named($item, $locale, $type, $key).' '.$margin.(is_string($pct) ? ' ('.$pct.'%)' : '');
        }

        return $parts === [] ? $this->template('weekly.none', $locale) : implode(', ', $parts);
    }

    /**
     * The channels and their shares of the money taken: "Cash 66.70%, Telebirr 33.30%" (four at most), or "none".
     *
     * @param  array<string, mixed>  $p
     */
    private function shares(string $type, array $p, string $locale): string
    {
        $parts = [];
        foreach (array_slice($this->rows($type, $p, 'channels'), 0, 4) as $channel) {
            $share = $channel['share'] ?? null;
            $amount = $channel['amount'] ?? null;
            $parts[] = $this->named($channel, $locale, $type, 'channels').' '.(is_string($share) ? $share.'%' : (is_string($amount) ? $amount : ''));
        }

        return $parts === [] ? $this->template('weekly.none', $locale) : implode(', ', $parts);
    }

    /**
     * How many stock-outs the week had and the items that ran out most: "4 (Flour 2, Croissant 1)", or "none".
     *
     * @param  array<string, mixed>  $p
     */
    private function stockouts(string $type, array $p, string $locale): string
    {
        $count = $this->int($type, $p, 'stockout_count');
        if ($count === 0) {
            return $this->template('weekly.none', $locale);
        }
        $parts = [];
        foreach (array_slice($this->rows($type, $p, 'stockout_items'), 0, 3) as $item) {
            $times = $item['stockout_count'] ?? null;
            $parts[] = $this->named($item, $locale, $type, 'stockout_items').' '.(is_int($times) ? (string) $times : '');
        }

        return $parts === [] ? (string) $count : $count.' ('.implode(', ', $parts).')';
    }

    /**
     * @param  array<string, mixed>  $p
     * @return list<array<string, mixed>>
     */
    private function rows(string $type, array $p, string $key): array
    {
        $rows = $p[$key] ?? null;
        if (! is_array($rows)) {
            throw MissingPayloadKey::for($type, $key);
        }
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw MissingPayloadKey::for($type, $key);
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * A row's name in the reader's language (English when it has no Amharic one), at most 40 characters.
     *
     * @param  array<string, mixed>  $row
     */
    private function named(array $row, string $locale, string $type, string $key): string
    {
        $am = $row['name_am'] ?? null;
        $name = $locale === 'am' && is_string($am) && trim($am) !== '' ? $am : ($row['name_en'] ?? null);
        if (! is_string($name)) {
            throw MissingPayloadKey::for($type, $key);
        }

        return mb_strimwidth($name, 0, 40, '…');
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
