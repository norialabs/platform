<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tests\Fixtures;

use NoriaLabs\Platform\Auth\OtpChallenge;

/** What a host would write to hang its own relations off a sign-in code. */
class HostOtpChallenge extends OtpChallenge
{
    public function label(): string
    {
        return 'host';
    }
}
