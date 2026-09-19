<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tests\Fixtures;

use NoriaLabs\Platform\Contracts\PermissionAction;
use NoriaLabs\Platform\Contracts\ScopedPermissionResource;

/** A product with more than one side: a tenant role must not see the platform ones. */
enum SidedResource: string implements ScopedPermissionResource
{
    case Invoice = 'invoice';
    case Report = 'report';
    case Ledger = 'ledger';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function scope(): string
    {
        return $this === self::Ledger ? 'platform' : 'tenant';
    }

    public function actions(): array
    {
        return $this === self::Report ? [Action::View] : [Action::View, Action::Create, Action::Delete];
    }

    public function supports(PermissionAction $action): bool
    {
        return in_array($action, $this->actions(), true);
    }
}
