<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Shared\Values\Quantity;

/**
 * One line as the pad or the till sends it (POST /orders, PUT /orders/{order}/lines): its client UUID (lower case),
 * the product, how many (units of the product, a decimal string at 4 dp), the modifiers chosen, a note for the station.
 */
final readonly class LineInput
{
    /**
     * @param  list<int>  $modifierIds
     */
    public function __construct(
        public int $index,
        public string $clientUuid,
        public int $itemId,
        public Quantity $qty,
        public array $modifierIds,
        public ?string $notes,
    ) {}

    public function context(): LineContext
    {
        return new LineContext($this->clientUuid, $this->itemId, $this->index);
    }
}
