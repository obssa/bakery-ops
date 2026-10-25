<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /payment-channels/order: {ids}, the channel ids in their new order (at most 200). That the list names every live channel of the
 * branch exactly once is Payments' rule (ReorderChannels: 422 payments.channel.order_incomplete).
 */
final class ReorderChannelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'list', 'min:1', 'max:200'],
            'ids.*' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->validated()['ids'];

        return array_map('intval', $ids);
    }
}
