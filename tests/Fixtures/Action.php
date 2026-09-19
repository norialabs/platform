<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tests\Fixtures;

use NoriaLabs\Platform\Contracts\PermissionAction;

enum Action: string implements PermissionAction
{
    case View = 'view';
    case Create = 'create';
    case Delete = 'delete';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
