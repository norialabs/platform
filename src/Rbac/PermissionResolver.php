<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Rbac;

use Illuminate\Contracts\Auth\Authenticatable;
use NoriaLabs\Platform\Contracts\PermissionAction;
use NoriaLabs\Platform\Contracts\PermissionCeiling;
use NoriaLabs\Platform\Contracts\PermissionResource;
use NoriaLabs\Platform\Contracts\PrincipalResolver;
use NoriaLabs\Platform\Contracts\RoleRepository;

class PermissionResolver
{
    /** @var array<string, Permissions> */
    private array $resolved = [];

    public function __construct(
        private PrincipalResolver $principals,
        private RoleRepository $roles,
        private ?PermissionCeiling $ceiling = null,
    ) {}

    public function allows(Authenticatable $user, PermissionResource $resource, PermissionAction $action): bool
    {
        return $this->permissionsFor($user)->has($resource, $action);
    }

    public function permissionsFor(Authenticatable $user): Permissions
    {
        $principal = $this->principals->for($user);

        if ($principal === null) {
            return Permissions::none();
        }

        $scope = Catalog::scopeKey($principal->scope());
        $key = $scope.'|'.($principal->contextId() ?? '').'|'.implode(',', $principal->roleSlugs());

        return $this->resolved[$key] ??= $this->roles
            ->permissionsFor($scope, $principal->roleSlugs())
            ->withinCeiling($this->ceiling?->for($user));
    }

    public function flush(): void
    {
        $this->resolved = [];
    }
}
