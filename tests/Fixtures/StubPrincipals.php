<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use NoriaLabs\Platform\Contracts\Principal;
use NoriaLabs\Platform\Contracts\PrincipalResolver;

class StubPrincipals implements PrincipalResolver
{
    public static ?Principal $principal = null;

    public static int $calls = 0;

    public function for(Authenticatable $user): ?Principal
    {
        self::$calls++;

        return self::$principal;
    }
}
