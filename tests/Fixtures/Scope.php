<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tests\Fixtures;

enum Scope: string
{
    case Tenant = 'tenant';
    case Platform = 'platform';
}
