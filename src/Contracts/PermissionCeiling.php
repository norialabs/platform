<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use NoriaLabs\Platform\Rbac\Permissions;

/**
 * An upper bound on what a principal may exercise on this request, whatever
 * their roles say: an API token's abilities, or a plan that no longer covers
 * a module. Null means no ceiling.
 */
interface PermissionCeiling
{
    public function for(Authenticatable $user): ?Permissions;
}
