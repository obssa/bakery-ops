<?php

declare(strict_types=1);

namespace App\Shared;

use Throwable;

/**
 * The marker of a domain refusal (step 5.5a, ADR 0021): the request was understood and a rule refused it, answered with a stable
 * message_key (rule 9) and a 4xx. That is the system working, not failing, so bootstrap/app.php does not report it (no ERROR line in the
 * log, nothing to Sentry) and logs it once at info with its key instead (Observability\RefusalLog).
 *
 * A refusal class that can also carry a server failure (FilesError: processing_failed is a 500, unavailable a 503) says so through
 * httpStatus(): a 5xx is reported like any other failure. tests/Arch/DomainRefusalTest.php keeps every refusal class marked.
 */
interface DomainRefusal extends Throwable
{
    /** The stable message_key the client is answered with: the only thing the info line carries. */
    public function messageKey(): string;

    /** The HTTP status the client is answered with. */
    public function httpStatus(): int;
}
