<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Db;

use InvalidArgumentException;

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

    public static function quoted(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            throw new InvalidArgumentException("Unsafe identifier [{$value}].");
        }

        return '"'.$value.'"';
    }
}
