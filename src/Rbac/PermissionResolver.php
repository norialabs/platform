<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Rbac;

use Illuminate\Contracts\Auth\Authenticatable;
use NoriaLabs\Platform\Contracts\PermissionAction;
use NoriaLabs\Platform\Contracts\PermissionCeiling;
use NoriaLabs\Platform\Contracts\PermissionResource;
use NoriaLabs\Platform\Contracts\PrincipalResolver;
use NoriaLabs\Platform\Contracts\RoleRepository;

/**
 * What the caller may do, once. Registered scoped, so a request that checks
 * forty gates reads the roles once rather than forty times.
 *
 * The ceiling is applied after the roles are merged and never before: a role
 * that grants more than the token allows is not an error, it is a role being
 * exercised through a narrower door.
 */
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

        $key = $principal->scope().'|'.($principal->contextId() ?? '').'|'.implode(',', $principal->roleSlugs());

        return $this->resolved[$key] ??= $this->roles
            ->permissionsFor($principal->scope(), $principal->roleSlugs())
            ->withinCeiling($this->ceiling?->for($user));
    }

    /** Between requests in a long-lived worker, and between tests. */
    public function flush(): void
    {
        $this->resolved = [];
    }
}
