<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

/**
 * What every log line should carry whether or not the caller passed it:
 * a request id, the route, the workspace, the actor.
 *
 * The host's, because what identifies a request differs per product, and
 * the package has no business deciding that a console run is 'console'.
 * Bind it and every line through Logger picks it up; leave it unbound and
 * lines carry only what the caller gave.
 */
interface LogContext
{
    /** @return array<string, mixed> */
    public function capture(): array;
}
