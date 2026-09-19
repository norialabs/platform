<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

interface LogContext
{
    /** @return array<string, mixed> */
    public function capture(): array;
}
