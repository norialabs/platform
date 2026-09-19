<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tests\Fixtures;

use NoriaLabs\Platform\Auth\OtpChallenge;

class HostOtpChallenge extends OtpChallenge
{
    public function label(): string
    {
        return 'host';
    }
}
