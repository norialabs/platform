<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tests\Fixtures;

use NoriaLabs\Platform\Contracts\PermissionAction;
use NoriaLabs\Platform\Contracts\PermissionResource;

enum Resource: string implements PermissionResource
{
    case Invoice = 'invoice';
    case Report = 'report';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function actions(): array
    {
        return match ($this) {
            self::Invoice => [Action::View, Action::Create, Action::Delete],
            self::Report => [Action::View],
        };
    }

    public function supports(PermissionAction $action): bool
    {
        return in_array($action, $this->actions(), true);
    }
}
