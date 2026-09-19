<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Auth;

use RuntimeException;

class UnscopedToken extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A personal access token must name exactly one workspace.');
    }
}
