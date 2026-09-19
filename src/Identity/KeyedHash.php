<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Identity;

use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Keyed SHA-256, for a value this system must look up and must never read
 * back: one-time codes, invitation tokens, and the destinations both are
 * sent to.
 *
 * The key is what makes it unguessable - a plain digest of a Kenyan mobile
 * is 10^8, seconds of work - and it is not in the database, so a dump of
 * the table alone reverses nothing.
 */
final class KeyedHash
{
    public function __construct(private ?string $key = null) {}

    public function of(string $value): string
    {
        return hash_hmac('sha256', $value, $this->key());
    }

    public function matches(string $value, string $hash): bool
    {
        return hash_equals($hash, $this->of($value));
    }

    /**
     * Its own key where the product set one, so rotating the application
     * key does not orphan every outstanding invitation at once.
     */
    private function key(): string
    {
        if ($this->key !== null && $this->key !== '') {
            return $this->key;
        }

        $configured = Config::get('noria.identity.hash_key');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $fallback = Config::get('app.key');

        if (is_string($fallback) && $fallback !== '') {
            return $fallback;
        }

        throw new RuntimeException(
            'A keyed hash needs a key: set noria.identity.hash_key, or the application key.'
        );
    }
}
