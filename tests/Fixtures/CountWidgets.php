<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tests\Fixtures;

use NoriaLabs\Platform\Tenancy\Tenancy;
use NoriaLabs\Platform\Tenancy\TenantJob;

class CountWidgets extends TenantJob
{
    public static ?string $ranInside = null;

    public function work(Tenancy $tenancy): void
    {
        self::$ranInside = $tenancy->id();
    }
}
