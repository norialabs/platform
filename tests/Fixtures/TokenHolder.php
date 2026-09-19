<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

/** Somebody holding a token, or nobody holding one. */
class TokenHolder extends User
{
    /** @param list<string>|null $abilities */
    public function __construct(private ?array $abilities = null)
    {
        parent::__construct();
    }

    public function currentAccessToken(): ?object
    {
        return $this->abilities === null ? null : (object) ['abilities' => $this->abilities];
    }
}
