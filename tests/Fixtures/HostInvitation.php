<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tests\Fixtures;

use NoriaLabs\Platform\Invitations\Invitation;

class HostInvitation extends Invitation
{
    public function label(): string
    {
        return 'host';
    }
}
