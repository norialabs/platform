<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tests\Fixtures;

use BackedEnum;
use NoriaLabs\Platform\Contracts\Principal;

class StubPrincipal implements Principal
{
    /** @param list<string> $roles */
    public function __construct(
        private string|BackedEnum $scope,
        private array $roles,
        private ?string $contextId = null,
    ) {}

    public function scope(): string|BackedEnum
    {
        return $this->scope;
    }

    public function roleSlugs(): array
    {
        return $this->roles;
    }

    public function contextId(): ?string
    {
        return $this->contextId;
    }
}
