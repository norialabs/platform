<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Identity;

use Stringable;

/**
 * An email address or a phone number, normalised to the one spelling the
 * rest of the system stores and hashes.
 *
 * Every path that identifies a person by a channel - one-time codes,
 * invitations, social email matching, account linking - parses through
 * here, because two spellings of one address that hash differently are two
 * accounts for one person.
 */
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

    /** Whether this destination is the kind the named channel can deliver to. */
    public function suits(Channel $channel): bool
    {
        return $this->isEmail === $channel->needsEmail();
    }

    /**
     * Enough to recognise which of your addresses was used, never enough to
     * reconstruct one you have not seen. The only form of a destination
     * ever stored in clear.
     */
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
