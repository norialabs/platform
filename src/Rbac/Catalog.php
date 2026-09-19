<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Rbac;

use BackedEnum;
use Illuminate\Support\Facades\Config;
use NoriaLabs\Platform\Contracts\PermissionAction;
use NoriaLabs\Platform\Contracts\PermissionResource;
use NoriaLabs\Platform\Contracts\ScopedPermissionResource;
use RuntimeException;

final class Catalog
{
    /** @return list<PermissionResource> */
    public static function resources(): array
    {
        $cases = [];

        foreach (self::cases('resources', PermissionResource::class) as $case) {
            if ($case instanceof PermissionResource) {
                $cases[] = $case;
            }
        }

        return $cases;
    }

    /** @return list<PermissionAction> */
    public static function actions(): array
    {
        $cases = [];

        foreach (self::cases('actions', PermissionAction::class) as $case) {
            if ($case instanceof PermissionAction) {
                $cases[] = $case;
            }
        }

        return $cases;
    }

    /**
     * @return list<PermissionResource>
     */
    public static function forScope(string $scope): array
    {
        $scoped = [];

        foreach (self::resources() as $resource) {
            if (! $resource instanceof ScopedPermissionResource || $resource->scope() === $scope) {
                $scoped[] = $resource;
            }
        }

        return $scoped;
    }

    public static function resource(string $value): ?PermissionResource
    {
        foreach (self::resources() as $resource) {
            if ($resource->value === $value) {
                return $resource;
            }
        }

        return null;
    }

    public static function action(string $value): ?PermissionAction
    {
        foreach (self::actions() as $action) {
            if ($action->value === $value) {
                return $action;
            }
        }

        return null;
    }

    /**
     * @param  class-string  $contract
     * @return list<BackedEnum>
     */
    private static function cases(string $key, string $contract): array
    {
        $enum = Config::get('noria.rbac.'.$key);

        if (! is_string($enum) || ! enum_exists($enum) || ! is_a($enum, $contract, allow_string: true)) {
            throw new RuntimeException(
                "noria.rbac.{$key} must name a backed enum implementing ".$contract.'.'
            );
        }

        /** @var list<BackedEnum> */
        return $enum::cases();
    }
}
