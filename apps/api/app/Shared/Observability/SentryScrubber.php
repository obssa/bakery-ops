<?php

declare(strict_types=1);

namespace App\Shared\Observability;

use App\Shared\DomainRefusal;
use App\Shared\Files\LogScrubProcessor;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Stacktrace;
use Sentry\UserDataBag;

/**
 * Sentry's before_send (step 5.5a, ADR 0021, plan 10.2 D21): nothing personal leaves the host. Wherever it sits (the request, the exception
 * messages, the breadcrumbs, extra, tags, contexts, the transaction name), it removes
 *  - the value of every key that names a secret or a person's number (pin, password, token, secret, short_code, authorization, cookie,
 *    phone, signature, session, credential, api_key), at any depth;
 *  - PINs, passwords and tokens written as `key=value` or `key: value` in free text, Bearer and Sanctum tokens;
 *  - phone numbers (+251..., 2519..., 09..., 07..., any +international number);
 *  - the signature= and expires= of a signed URL (and S3's presigned parameters), with LogScrubProcessor's rule;
 *  - the whole body of an identity route (login, short-code, users, stations, devices: names, phones, PINs, passwords);
 *  - the cookies and the server environment of the request, the arguments the stack frames captured, everything of the user but its id.
 * A domain refusal never gets here (bootstrap/app.php does not report it); if one ever does, it is dropped.
 *
 * Pure (no container, no config), so the unit test runs it on hand-made events, and config:cache keeps it as [class, method].
 */
final class SentryScrubber
{
    public const string FILTERED = '[Filtered]';

    /** A key whose value never leaves (searched inside the key, any case: current_password, X-XSRF-TOKEN, customer_phone, new_pin). */
    private const string SECRET_KEY = '/(?:^|[^a-z])pins?(?:[^a-z]|$)|password|passwd|passcode|secret|token|short_?code|authori[sz]ation|cookie|phone|msisdn|signature|session|credential|api_?key/i';

    /** Identity's routes (routes/modules/identity.php): their bodies carry names, phone numbers, PINs and passwords, so none is sent. */
    private const string IDENTITY_PATH = '#^/api/v1/(?:login|logout|me|short-code|users|stations|devices|broadcasting/auth)(?:/|$)#';

    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        $exception = $hint?->exception;
        if ($exception instanceof DomainRefusal && $exception->httpStatus() < 500) {
            return null;
        }

        $message = $event->getMessage();
        if ($message !== null) {
            $formatted = $event->getMessageFormatted();
            $event->setMessage(self::scrubText($message), self::scrubArray($event->getMessageParams()), $formatted === null ? null : self::scrubText($formatted));
        }

        foreach ($event->getExceptions() as $bag) {
            $bag->setValue(self::scrubText($bag->getValue()));
            self::clearVars($bag->getStacktrace());
        }
        self::clearVars($event->getStacktrace());

        $event->setRequest(self::request($event->getRequest()));

        $event->setBreadcrumb(array_map(static fn (Breadcrumb $crumb): Breadcrumb => new Breadcrumb(
            $crumb->getLevel(),
            $crumb->getType(),
            $crumb->getCategory(),
            $crumb->getMessage() === null ? null : self::scrubText($crumb->getMessage()),
            self::scrubArray($crumb->getMetadata()),
            $crumb->getTimestamp(),
        ), $event->getBreadcrumbs()));

        $event->setExtra(self::scrubArray($event->getExtra()));

        $tags = [];
        foreach ($event->getTags() as $name => $value) {
            $tags[$name] = self::isSecretKey($name) ? self::FILTERED : self::scrubText($value);
        }
        $event->setTags($tags);

        foreach ($event->getContexts() as $name => $data) {
            $event->setContext($name, self::scrubArray($data));
        }

        $user = $event->getUser();
        $event->setUser($user === null || $user->getId() === null ? null : new UserDataBag($user->getId()));

        $transaction = $event->getTransaction();
        if ($transaction !== null) {
            $event->setTransaction(self::scrubText($transaction));
        }

        return $event;
    }

    /** Free text with the secrets, phone numbers and signed-URL parameters taken out; everything else as it was. */
    public static function scrubText(string $text): string
    {
        $text = LogScrubProcessor::scrubString($text);
        // a bearer token, and a Sanctum token on its own (id|secret)
        $text = (string) preg_replace('/\bBearer\s+[^\s"\',;)]+/i', 'Bearer '.self::FILTERED, $text);
        $text = (string) preg_replace('/\b\d+\|[A-Za-z0-9]{20,}\b/', self::FILTERED, $text);
        // pin=1234, "password":"x", token: abc
        $text = (string) preg_replace(
            '/\b(pins?|password|passwd|passcode|secret|token|short_?code|api_?key)("?\s*[=:]\s*"?)[^\s"\',;&)}\]]+/i',
            '$1$2'.self::FILTERED,
            $text,
        );
        // phone numbers: any +international number (spaces, dots, dashes, brackets allowed), then Ethiopian ones without the plus
        $text = (string) preg_replace('/\+\d[\d\s().-]{6,}\d/', '[phone]', $text);

        return (string) preg_replace('/(?<![\w.+])(?:251|0)[79]\d{8}(?![\w.])/', '[phone]', $text);
    }

    /**
     * @param  array<array-key, mixed>  $request
     * @return array<array-key, mixed>
     */
    private static function request(array $request): array
    {
        unset($request['cookies'], $request['env']);

        $path = is_string($request['url'] ?? null) ? parse_url($request['url'], PHP_URL_PATH) : null;
        if (is_string($request['url'] ?? null)) {
            $request['url'] = self::scrubText($request['url']);
        }
        if (is_string($request['query_string'] ?? null)) {
            $request['query_string'] = ltrim(self::scrubText('?'.$request['query_string']), '?');
        }
        if (is_array($request['headers'] ?? null)) {
            $request['headers'] = self::scrubArray($request['headers']);
        }
        if (array_key_exists('data', $request)) {
            $request['data'] = is_string($path) && preg_match(self::IDENTITY_PATH, $path) === 1
                ? self::FILTERED
                : self::scrubValue($request['data']);
        }

        return $request;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function scrubArray(array $values): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = is_string($key) && self::isSecretKey($key) ? self::FILTERED : self::scrubValue($value);
        }

        return $values;
    }

    private static function scrubValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::scrubText($value);
        }

        return is_array($value) ? self::scrubArray($value) : $value;
    }

    private static function isSecretKey(string $key): bool
    {
        return preg_match(self::SECRET_KEY, $key) === 1;
    }

    private static function clearVars(?Stacktrace $stacktrace): void
    {
        foreach ($stacktrace?->getFrames() ?? [] as $frame) {
            $frame->setVars([]);
        }
    }
}
