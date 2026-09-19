<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tests\Fixtures;

use NoriaLabs\Platform\Contracts\RoleRepository;
use NoriaLabs\Platform\Rbac\Permissions;

class StubRoles implements RoleRepository
{
    /** @var array<string, array<string, list<string>>> */
    public static array $grants = [];

    public static int $calls = 0;

    public static ?string $scope = null;

    public function permissionsFor(string $scope, array $slugs): Permissions
    {
        self::$calls++;
        self::$scope = $scope;

        $permissions = Permissions::none();

        foreach ($slugs as $slug) {
            $permissions = $permissions->merge(Permissions::fromArray(self::$grants[$slug] ?? []));
        }

        return $permissions;
    }
}
