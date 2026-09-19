<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

interface Principal
{
    public function scope(): string;

    /** @return list<string> */
    public function roleSlugs(): array;

    public function contextId(): ?string;
}
