<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Http;

/**
 * Which hops in front of the application may be believed about who the
 * caller is.
 *
 * Without this, every per-address limit counts the whole platform as one
 * caller and the auth trail records the balancer on every row. A list is
 * safer than '*', which believes whatever the last hop forwarded.
 *
 * Null is the default rather than '*': a container that is only ever reached
 * through its own proxy should say so, and one that is not should not be
 * believing forwarded headers at all.
 */
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
