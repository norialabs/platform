<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tests\Fixtures;

use NoriaLabs\Platform\Invitations\Invitation;

/** What a host would write to hang its own relations off an invitation. */
class HostInvitation extends Invitation
{
    public function label(): string
    {
        return 'host';
    }
}
