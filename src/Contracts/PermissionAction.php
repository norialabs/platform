<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

/**
 * A verb the product grants. Implemented by a backed enum in the host, whose
 * $value satisfies the property requirement.
 */
interface PermissionAction
{
    public string $value { get; }

    public function label(): string;
}
