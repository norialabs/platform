<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tests\Fixtures;

use NoriaLabs\Platform\Contracts\LogContext;

class StubLogContext implements LogContext
{
    /** @var array<string, mixed> */
    public static array $context = ['request_id' => 'req-1', 'path' => 'invoices'];

    public function capture(): array
    {
        return self::$context;
    }
}
