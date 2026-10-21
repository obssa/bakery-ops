<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Drivers;

/**
 * What the CI array driver was asked to send, and the failures a test asks it to simulate. Bound as a scoped service
 * (NotificationsServiceProvider), so it lives for one request or job and keeps no static state. Used only while
 * NOTIFY_ARRAY_ENABLED is true (CI, tests); the live host never switches it on.
 */
final class SentLog
{
    /** @var list<array<string, mixed>> */
    private array $sent = [];

    /** @var array<int, string> endpoint id => the error its sends fail with */
    private array $failures = [];

    /** @var array<int, true> endpoint ids whose sends say the address is gone */
    private array $gone = [];

    private ?string $failAll = null;

    /**
     * @param  array<string, mixed>  $entry
     */
    public function record(array $entry): void
    {
        $this->sent[] = $entry;
    }

    /** @return list<array<string, mixed>> */
    public function sent(): array
    {
        return $this->sent;
    }

    /** Every send to this endpoint fails with $message. */
    public function fail(int $endpointId, string $message): void
    {
        $this->failures[$endpointId] = $message;
    }

    /** Every send fails with $message. */
    public function failAll(string $message): void
    {
        $this->failAll = $message;
    }

    /** Every send to this endpoint ends as gone. */
    public function gone(int $endpointId): void
    {
        $this->gone[$endpointId] = true;
    }

    public function failureFor(int $endpointId): ?string
    {
        return $this->failures[$endpointId] ?? $this->failAll;
    }

    public function isGone(int $endpointId): bool
    {
        return isset($this->gone[$endpointId]);
    }

    public function clear(): void
    {
        $this->sent = [];
        $this->failures = [];
        $this->gone = [];
        $this->failAll = null;
    }
}
