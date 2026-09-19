<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Identity;

use Stringable;

final class Destination implements Stringable
{
    public const MAX_LENGTH = 128;

    private function __construct(
        public readonly string $value,
        public readonly bool $isEmail,
    ) {}

    public static function tryFrom(?string $raw, ?string $country = null): ?self
    {
        $trimmed = trim((string) $raw);

        if ($trimmed === '') {
            return null;
        }

        return str_contains($trimmed, '@')
            ? self::email($trimmed)
            : self::phone($trimmed, $country);
    }

    public static function isValid(?string $raw, ?string $country = null): bool
    {
        return self::tryFrom($raw, $country) !== null;
    }

    public function suits(Channel $channel): bool
    {
        return $this->isEmail === $channel->needsEmail();
    }

    /**
     * What a person should see beside the hash. `masked` is the safe default;
     * `plain` is for a list whose reader supplied the address in the first
     * place, such as a workspace admin reviewing who they invited.
     */
    public function hint(string $style): string
    {
        return $style === 'plain' ? $this->value : $this->masked();
    }

    public function masked(): string
    {
        if (! $this->isEmail) {
            return Phone::tryFrom($this->value)?->masked() ?? '';
        }

        [$local, $domain] = explode('@', $this->value, 2);

        return substr($local, 0, 1).str_repeat('*', max(strlen($local) - 1, 1)).'@'.$domain;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function email(string $raw): ?self
    {
        $email = strtolower($raw);

        if (strlen($email) > self::MAX_LENGTH || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return new self($email, true);
    }

    private static function phone(string $raw, ?string $country): ?self
    {
        $e164 = Phone::normalise($raw, $country);

        return $e164 === null ? null : new self($e164, false);
    }
}
