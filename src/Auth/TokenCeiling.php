<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use NoriaLabs\Platform\Contracts\PermissionCeiling;
use NoriaLabs\Platform\Rbac\Catalog;
use NoriaLabs\Platform\Rbac\Permissions;

class TokenCeiling implements PermissionCeiling
{
    public function for(Authenticatable $user): ?Permissions
    {
        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        if (! is_object($token)) {
            return null;
        }

        $abilities = $token->abilities ?? [];
        $abilities = is_array($abilities) ? $abilities : [];

        if (in_array(TokenAbilities::ALL, $abilities, true)) {
            return Permissions::all();
        }

        $grants = [];

        foreach ($abilities as $ability) {
            if (! is_string($ability) || str_starts_with($ability, TokenAbilities::WORKSPACE_PREFIX)) {
                continue;
            }

            [$resource, $action] = array_pad(explode(':', $ability, 2), 2, null);

            if (Catalog::resource((string) $resource) === null || Catalog::action((string) $action) === null) {
                continue;
            }

            $grants[(string) $resource][] = (string) $action;
        }

        return Permissions::fromArray($grants);
    }
}
