<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Rbac;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;
use NoriaLabs\Platform\Contracts\PermissionAction;
use NoriaLabs\Platform\Contracts\PermissionResource;

/**
 * A role's permission document, validated against the product's catalogue.
 * The wildcard is legal in either position and only ever written by a system
 * role.
 *
 *     {"account": ["view", "create"], "deal": ["view", "advance"]}
 *     {"*": ["*"]}
 *
 * A value object rather than a bare array, because the array form was
 * written by a request body or by an older version of this class and both
 * have to be treated as untrusted on the way in.
 *
 * @implements Arrayable<string, list<string>>
 */
final class Permissions implements Arrayable, JsonSerializable
{
    public const WILDCARD = '*';

    /** @param array<string, list<string>> $grants */
    private function __construct(private readonly array $grants) {}

    /** @param array<string, mixed> $grants */
    public static function fromArray(array $grants): self
    {
        return new self(self::validate($grants));
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function all(): self
    {
        return new self([self::WILDCARD => [self::WILDCARD]]);
    }

    public function has(PermissionResource $resource, PermissionAction $action): bool
    {
        $actions = $this->grants[$resource->value] ?? $this->grants[self::WILDCARD] ?? null;

        if ($actions === null) {
            return false;
        }

        return in_array(self::WILDCARD, $actions, true) || in_array($action->value, $actions, true);
    }

    public function grant(PermissionResource $resource, PermissionAction ...$actions): self
    {
        $existing = $this->grants[self::key($resource)] ?? [];
        $added = array_map(self::key(...), $actions);

        return self::fromArray([
            ...$this->grants,
            self::key($resource) => array_values(array_unique([...$existing, ...$added])),
        ]);
    }

    public function revoke(PermissionResource $resource): self
    {
        $grants = $this->grants;
        unset($grants[$resource->value]);

        return new self($grants);
    }

    /** Everything both documents grant, for a principal holding two roles. */
    public function merge(self ...$others): self
    {
        $merged = $this->grants;

        foreach ($others as $other) {
            foreach ($other->grants as $resource => $actions) {
                $merged[$resource] = array_values(array_unique([...$merged[$resource] ?? [], ...$actions]));
            }
        }

        return new self($merged);
    }

    /**
     * Trimmed to what a ceiling also grants. An invited member cannot be
     * given more than the person inviting them holds, and a plan downgrade
     * has to take away what the plan no longer covers.
     */
    public function withinCeiling(?self $ceiling): self
    {
        if ($ceiling === null || $ceiling->grantsEverything()) {
            return $this;
        }

        $trimmed = [];

        foreach ($this->grants as $resourceValue => $actions) {
            $allowed = $ceiling->grants[$resourceValue] ?? $ceiling->grants[self::WILDCARD] ?? [];

            if (in_array(self::WILDCARD, $allowed, true)) {
                $trimmed[$resourceValue] = $actions;

                continue;
            }

            $kept = in_array(self::WILDCARD, $actions, true)
                ? $allowed
                : array_values(array_intersect($actions, $allowed));

            if ($kept !== []) {
                $trimmed[$resourceValue] = array_values($kept);
            }
        }

        return new self($trimmed);
    }

    public function isEmpty(): bool
    {
        return $this->grants === [];
    }

    public function grantsEverything(): bool
    {
        return in_array(self::WILDCARD, $this->grants[self::WILDCARD] ?? [], true);
    }

    /**
     * The catalogue a settings screen renders, derived from the enums so
     * it cannot drift from the values the gates actually check. Narrowed
     * to one scope where the product has more than one side: a tenant
     * role must never be offered a platform resource.
     *
     * @return list<array{resource: string, label: string, actions: list<array{action: string, label: string, granted: bool}>}>
     */
    public function catalog(?string $scope = null): array
    {
        $resources = $scope === null ? Catalog::resources() : Catalog::forScope($scope);

        return array_map(fn (PermissionResource $resource): array => [
            'resource' => self::key($resource),
            'label' => $resource->label(),
            'actions' => array_map(fn (PermissionAction $action): array => [
                'action' => self::key($action),
                'label' => $action->label(),
                'granted' => $this->has($resource, $action),
            ], $resource->actions()),
        ], $resources);
    }

    /** @return array<string, list<string>> */
    public function toArray(): array
    {
        return $this->grants;
    }

    /** @return array<string, list<string>> */
    public function jsonSerialize(): array
    {
        return $this->grants;
    }

    /**
     * @param  array<string, mixed>  $grants
     * @return array<string, list<string>>
     */
    private static function validate(array $grants): array
    {
        $clean = [];

        foreach ($grants as $resourceValue => $actions) {
            $resourceValue = (string) $resourceValue;

            if (! is_array($actions)) {
                throw new InvalidArgumentException("Permission grants for [{$resourceValue}] must be a list of actions.");
            }

            if ($resourceValue === self::WILDCARD) {
                $clean[self::WILDCARD] = self::validateWildcardActions($actions);

                continue;
            }

            $resource = Catalog::resource($resourceValue)
                ?? throw new InvalidArgumentException("Unknown permission resource [{$resourceValue}].");

            $clean[self::key($resource)] = self::validateActions($resource, $actions);
        }

        return $clean;
    }

    /**
     * @param  array<mixed>  $actions
     * @return list<string>
     */
    private static function validateWildcardActions(array $actions): array
    {
        foreach ($actions as $action) {
            $value = self::stringify($action);

            if ($value !== self::WILDCARD && Catalog::action($value) === null) {
                throw new InvalidArgumentException("Unknown permission action [{$value}].");
            }
        }

        return array_values(array_unique(array_map(self::stringify(...), $actions)));
    }

    /**
     * @param  array<mixed>  $actions
     * @return list<string>
     */
    private static function validateActions(PermissionResource $resource, array $actions): array
    {
        foreach ($actions as $action) {
            $value = self::stringify($action);

            if ($value === self::WILDCARD) {
                continue;
            }

            $parsed = Catalog::action($value)
                ?? throw new InvalidArgumentException("Unknown permission action [{$value}].");

            if (! $resource->supports($parsed)) {
                throw new InvalidArgumentException(
                    "Resource [{$resource->value}] does not support action [{$parsed->value}]."
                );
            }
        }

        return array_values(array_unique(array_map(self::stringify(...), $actions)));
    }

    private static function stringify(mixed $action): string
    {
        if ($action instanceof PermissionAction) {
            return self::key($action);
        }

        return is_scalar($action) ? (string) $action : '';
    }

    /**
     * The stored key for a resource or a verb.
     *
     * BackedEnum::$value is string|int and the column is a string, so an
     * int backed catalogue is normalised rather than refused: which keys a
     * product uses is the product's decision, not this package's.
     */
    private static function key(PermissionResource|PermissionAction $case): string
    {
        return (string) $case->value;
    }
}
