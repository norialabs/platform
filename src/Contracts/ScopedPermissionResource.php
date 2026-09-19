<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

interface ScopedPermissionResource extends PermissionResource
{
    public function scope(): string;
}
