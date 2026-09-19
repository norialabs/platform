<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

/**
 * A resource that belongs to one side of the product.
 *
 * A tenant role must never be offered a platform resource, and a settings
 * screen that lists every resource in the catalogue offers exactly that.
 * Optional: a product with one side implements PermissionResource alone
 * and every resource is in scope.
 */
interface ScopedPermissionResource extends PermissionResource
{
    public function scope(): string;
}
