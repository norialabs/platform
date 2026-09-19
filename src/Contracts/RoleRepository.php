<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

use NoriaLabs\Platform\Rbac\Permissions;

/**
 * What a set of role slugs grants in a scope. The host owns this because the
 * roles are rows in the host's schema, some of them system roles it ships
 * and some of them written by a customer.
 */
interface RoleRepository
{
    /** @param list<string> $slugs */
    public function permissionsFor(string $scope, array $slugs): Permissions;
}
