<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Identity\Contracts\Access;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Actions\DeleteChannel;
use App\Modules\Payments\Actions\ReorderChannels;
use App\Modules\Payments\Actions\SaveChannel;
use App\Modules\Payments\Contracts\Channels;
use App\Modules\Payments\Exceptions\PaymentError;
use App\Modules\Payments\Http\Requests\ReorderChannelsRequest;
use App\Modules\Payments\Http\Requests\StoreChannelRequest;
use App\Modules\Payments\Http\Requests\UpdateChannelRequest;
use App\Modules\Payments\Models\PaymentChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The payment channels (phase-4 plan section 4.2, step 4.3a). The list is the till's (payments.take, as in Phase 1); the writes are
 * the admin's (channels.manage, the route's middleware), each one AuditedAction behind an Idempotency-Key. {channel} binds through
 * the BranchScoped model, so another branch's channel, and a removed one, is 404 (rule 8).
 *
 *   GET    /payment-channels             200 {data: [Channel]} the branch's channels, switched-off ones included, in their order;
 *                                        ?all=1 (channels.manage, else 403) adds the removed ones after them, with deleted_at
 *   POST   /payment-channels             201 Channel; 200 the same channel for a client_uuid already used
 *   PATCH  /payment-channels/{channel}   200 Channel
 *   PUT    /payment-channels/order       200 {data: [Channel]} the live channels in their new order
 *   DELETE /payment-channels/{channel}   200 Channel with deleted_at
 *
 * Channel = the Phase 1 fields (id, code, name_en, name_am, is_enabled, requires_photo, requires_reference, sort, accepted_now), then
 * reference_pattern, the hints, is_cash and deleted_at.
 */
final class ChannelsController
{
    public function index(Request $request, Access $access): JsonResponse
    {
        $query = PaymentChannel::query()->orderBy('sort')->orderBy('id');
        if ($request->boolean('all')) {
            $user = $request->user();
            if (! $user instanceof User || ! $access->can($user, 'channels.manage')) {
                throw PaymentError::forbidden();
            }
            $query = PaymentChannel::query()->withTrashed()->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')->orderBy('sort')->orderBy('id');
        }

        return response()->json(['data' => $query->get()->map(fn (PaymentChannel $c): array => self::resource($c))->values()->all()]);
    }

    public function store(StoreChannelRequest $input, SaveChannel $save): JsonResponse
    {
        [$channel, $created] = $save->create($input->fields());

        return response()->json(self::resource($channel), $created ? 201 : 200);
    }

    public function update(UpdateChannelRequest $input, PaymentChannel $channel, SaveChannel $save): JsonResponse
    {
        return response()->json(self::resource($save->update($channel, $input->changes())->refresh()));
    }

    public function reorder(ReorderChannelsRequest $input, ReorderChannels $reorder): JsonResponse
    {
        return response()->json(['data' => array_map(fn (PaymentChannel $c): array => self::resource($c), $reorder->handle($input->ids()))]);
    }

    public function destroy(PaymentChannel $channel, DeleteChannel $delete): JsonResponse
    {
        return response()->json(self::resource($delete->handle($channel)));
    }

    /**
     * @return array{id: int, code: string, name_en: string, name_am: string, is_enabled: bool, requires_photo: bool, requires_reference: bool, sort: int, accepted_now: bool, reference_pattern: string|null, reference_hint_en: string|null, reference_hint_am: string|null, photo_hint_en: string|null, photo_hint_am: string|null, is_cash: bool, deleted_at: string|null}
     */
    public static function resource(PaymentChannel $channel): array
    {
        return Channels::view($channel)->toArray() + ['deleted_at' => $channel->deleted_at?->toIso8601String()];
    }
}
