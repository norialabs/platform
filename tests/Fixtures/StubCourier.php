<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tests\Fixtures;

use NoriaLabs\Platform\Contracts\Courier;
use NoriaLabs\Platform\Identity\Channel;
use NoriaLabs\Platform\Identity\Destination;

class StubCourier implements Courier
{
    /** @var list<array{to: string, channel: string, kind: string, context: array<string, mixed>}> */
    public static array $sent = [];

    public function deliver(Destination $to, Channel $channel, string $kind, array $context): void
    {
        self::$sent[] = ['to' => $to->value, 'channel' => $channel->value, 'kind' => $kind, 'context' => $context];
    }
}
