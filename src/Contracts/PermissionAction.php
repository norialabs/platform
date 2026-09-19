<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

interface PermissionAction extends \BackedEnum
{
    public function label(): string;
}
