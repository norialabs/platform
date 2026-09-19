<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface PrincipalResolver
{
    public function for(Authenticatable $user): ?Principal;
}
