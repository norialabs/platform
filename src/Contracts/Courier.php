<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

use NoriaLabs\Platform\Identity\Channel;
use NoriaLabs\Platform\Identity\Destination;

interface Courier
{
    /** @param array<string, mixed> $context */
    public function deliver(Destination $to, Channel $channel, string $kind, array $context): void;
}
