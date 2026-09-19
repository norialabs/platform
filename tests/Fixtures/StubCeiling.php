<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use NoriaLabs\Platform\Contracts\PermissionCeiling;
use NoriaLabs\Platform\Rbac\Permissions;

class StubCeiling implements PermissionCeiling
{
    public static ?Permissions $ceiling = null;

    public function for(Authenticatable $user): ?Permissions
    {
        return self::$ceiling;
    }
}
