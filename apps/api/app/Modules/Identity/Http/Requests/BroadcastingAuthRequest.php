<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What Laravel Echo (pusher-js) sends before it joins a private channel: the socket id the transport gave the
 * connection ("1234.5678") and the channel name ("private-station.5"), as JSON or form-encoded.
 */
final class BroadcastingAuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        // the route's can:identity.authenticated lets every active user ask; ChannelRules decides the channel
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'socket_id' => ['required', 'string', 'regex:/\A\d+\.\d+\z/'],
            'channel_name' => ['required', 'string', 'max:200'],
        ];
    }

    public function channelName(): string
    {
        return $this->string('channel_name')->toString();
    }
}
