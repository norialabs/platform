<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Db;

use InvalidArgumentException;

/**
 * A database, role or schema name on its way into DDL, which cannot be
 * parameterised. Refused rather than quoted: a name that needs quoting is
 * a name somebody should not be passing here.
 */
final class Identifier
{
    private function __construct() {}

    public static function of(string $value): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $value) !== 1) {
            throw new InvalidArgumentException("Unsafe identifier [{$value}].");
        }

        return $value;
    }
}
