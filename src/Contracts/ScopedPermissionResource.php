<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

use BackedEnum;

interface ScopedPermissionResource extends PermissionResource
{
    public function scope(): string|BackedEnum;
}
