<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

use NoriaLabs\Platform\Identity\Channel;
use NoriaLabs\Platform\Identity\Destination;

/**
 * How a code or an invitation actually reaches somebody.
 *
 * The host's, because the wording, the template and the sending provider
 * are the product's. The package decides what is sent and to whom, never
 * how it looks.
 */
interface Courier
{
    /** @param array<string, mixed> $context */
    public function deliver(Destination $to, Channel $channel, string $kind, array $context): void;
}
