<?php

declare(strict_types=1);

namespace Tests\Support;

use Sentry\HttpClient\HttpClientInterface;
use Sentry\HttpClient\Request;
use Sentry\HttpClient\Response;
use Sentry\Options;

/**
 * A Sentry ingest that never leaves the process (step 5.5a, ADR 0021): handed to the SDK as its `http_client`, it keeps the body of every
 * envelope the real transport would have posted, after the SDK built and serialised it (so before_send has run) and before any network.
 */
final class FakeSentryIngest implements HttpClientInterface
{
    /** @var list<string> the raw envelopes, in order */
    public array $envelopes = [];

    public function sendRequest(Request $request, Options $options): Response
    {
        $this->envelopes[] = (string) $request->getStringBody();

        return new Response(200, [], '');
    }

    /** Everything that would have left the host, as one string. */
    public function raw(): string
    {
        return implode("\n", $this->envelopes);
    }

    /**
     * The event items of every envelope, decoded (an envelope is a header line, then an item header line and its payload line per item).
     *
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        $events = [];
        foreach ($this->envelopes as $envelope) {
            $lines = explode("\n", trim($envelope));
            for ($i = 1; $i + 1 < count($lines); $i += 2) {
                $header = json_decode($lines[$i], true);
                if (is_array($header) && ($header['type'] ?? null) === 'event') {
                    $payload = json_decode($lines[$i + 1], true);
                    if (is_array($payload)) {
                        $events[] = $payload;
                    }
                }
            }
        }

        return $events;
    }
}
