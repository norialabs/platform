<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Invitations;

use RuntimeException;

/**
 * Why an invitation could not be acted on. One exception rather than five,
 * because the caller has one thing to say and the reason is for the log.
 */
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
