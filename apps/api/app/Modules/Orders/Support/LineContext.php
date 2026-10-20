<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

/**
 * Which line a refusal is about, so the pad can show it on that line (phase-1 plan section 4): the line's client UUID
 * and product, its place in the request when it came in one (lines.{index}.*), its id once it is written.
 */
final readonly class LineContext
{
    public function __construct(
        public string $clientUuid,
        public int $itemId,
        public ?int $index = null,
        public ?int $lineId = null,
    ) {}

    /**
     * @param  string  $field  the request field of the line the refusal points at (item_id, modifier_ids, ...)
     * @return array<string, int|string>
     */
    public function toArray(string $field = 'item_id'): array
    {
        $context = ['line_client_uuid' => $this->clientUuid];
        if ($this->lineId !== null) {
            $context['line_id'] = $this->lineId;
        }
        $context['item_id'] = $this->itemId;
        if ($this->index !== null) {
            $context['field'] = "lines.{$this->index}.{$field}";
        }

        return $context;
    }
}
