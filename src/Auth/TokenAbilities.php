<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Auth;

use NoriaLabs\Platform\Contracts\PermissionAction;
use NoriaLabs\Platform\Contracts\PermissionResource;
use NoriaLabs\Platform\Rbac\Catalog;

/**
 * The vocabulary a personal access token is written in.
 *
 * Exactly one workspace:{uuid}, because the workspace is read from the
 * credential rather than from a header. The rest are {resource}:{action}
 * from the catalogue the gates check, or '*'. A token narrows a role and
 * can never widen one.
 */
final class TokenAbilities
{
    public const ALL = '*';

    public const WORKSPACE_PREFIX = 'workspace:';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private function __construct() {}

    /**
     * The one workspace this token is for. Null when there is none or more
     * than one: a token naming two workspaces is as unscoped as a token
     * naming none.
     *
     * @param  array<array-key, mixed>  $abilities
     */
    public static function workspaceIn(array $abilities): ?string
    {
        $found = [];

        foreach ($abilities as $ability) {
            if (is_string($ability) && str_starts_with($ability, self::WORKSPACE_PREFIX)) {
                $found[] = substr($ability, strlen(self::WORKSPACE_PREFIX));
            }
        }

        return count($found) === 1 && preg_match(self::UUID, $found[0]) === 1
            ? strtolower($found[0])
            : null;
    }

    public static function forWorkspace(string $workspaceId): string
    {
        return self::WORKSPACE_PREFIX.$workspaceId;
    }

    public static function permission(PermissionResource $resource, PermissionAction $action): string
    {
        return $resource->value.':'.$action->value;
    }

    /** '*', a workspace scope, or a pair the catalogue actually supports. */
    public static function isValid(string $ability): bool
    {
        if ($ability === self::ALL) {
            return true;
        }

        if (str_starts_with($ability, self::WORKSPACE_PREFIX)) {
            return preg_match(self::UUID, substr($ability, strlen(self::WORKSPACE_PREFIX))) === 1;
        }

        [$resource, $action] = array_pad(explode(':', $ability, 2), 2, null);

        $parsedResource = Catalog::resource((string) $resource);
        $parsedAction = Catalog::action((string) $action);

        return $parsedResource !== null && $parsedAction !== null && $parsedResource->supports($parsedAction);
    }
}
