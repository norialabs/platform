<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Invitations;

use RuntimeException;

class InvitationOutcome extends RuntimeException
{
    public static function unknown(): self
    {
        return new self('That invitation is no longer valid.');
    }

    public static function expired(): self
    {
        return new self('That invitation has expired.');
    }

    public static function wrongRecipient(): self
    {
        return new self('That invitation was issued to a different address.');
    }

    public static function notPending(): self
    {
        return new self('Only an open invitation can be withdrawn.');
    }
}
