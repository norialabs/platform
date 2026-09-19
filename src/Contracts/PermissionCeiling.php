<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use NoriaLabs\Platform\Rbac\Permissions;

interface PermissionCeiling
{
    public function for(Authenticatable $user): ?Permissions;
}
