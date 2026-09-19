<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Http;

final class TrustedProxies
{
    private function __construct() {}

    /** @return string|list<string>|null */
    public static function from(mixed $configured): string|array|null
    {
        $value = trim((string) (is_scalar($configured) ? $configured : ''));

        if ($value === '') {
            return null;
        }

        if ($value === '*') {
            return '*';
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $value))));
    }
}
