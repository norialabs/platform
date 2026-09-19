<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Identity;

use Illuminate\Support\Facades\Config;
use RuntimeException;

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
