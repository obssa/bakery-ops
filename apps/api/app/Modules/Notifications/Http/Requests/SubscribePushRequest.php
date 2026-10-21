<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /notifications/endpoints {driver: "push", subscription: {endpoint, keys: {p256dh, auth}}, user_agent?}: what the
 * browser's PushManager.subscribe() returns (its toJSON()). Only push subscribes this way; a Telegram chat is linked with a
 * one-time code instead (POST /notifications/telegram/link). The endpoint must be an https URL, as the Web Push protocol
 * requires.
 */
final class SubscribePushRequest extends FormRequest
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
            'driver' => ['required', 'string', 'in:push'],
            'subscription' => ['required', 'array'],
            'subscription.endpoint' => ['required', 'string', 'max:2000', 'url:https'],
            'subscription.keys' => ['required', 'array'],
            'subscription.keys.p256dh' => ['required', 'string', 'max:255'],
            'subscription.keys.auth' => ['required', 'string', 'max:255'],
            'user_agent' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return array{endpoint: string, keys: array{p256dh: string, auth: string}} */
    public function subscription(): array
    {
        /** @var array{endpoint: string, keys: array{p256dh: string, auth: string}} $subscription */
        $subscription = $this->validated('subscription');

        return [
            'endpoint' => $subscription['endpoint'],
            'keys' => ['p256dh' => $subscription['keys']['p256dh'], 'auth' => $subscription['keys']['auth']],
        ];
    }

    public function userAgent(): ?string
    {
        $agent = $this->validated('user_agent');

        return is_string($agent) && $agent !== '' ? $agent : null;
    }
}
