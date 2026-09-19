<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Auth;

use RuntimeException;

/**
 * Asked for a code again too soon. Carries the wait so a caller can say how
 * long rather than only that it refused.
 */
class OtpThrottled extends RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfter)
    {
        parent::__construct($message);
    }
}
