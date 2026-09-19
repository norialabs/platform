<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

use BackedEnum;

interface Principal
{
    public function scope(): string|BackedEnum;

    /** @return list<string> */
    public function roleSlugs(): array;

    public function contextId(): ?string;
}
