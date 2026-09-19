<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

use NoriaLabs\Platform\Rbac\Permissions;

interface RoleRepository
{
    /** @param list<string> $slugs */
    public function permissionsFor(string $scope, array $slugs): Permissions;
}
