<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Auth;

final class ProviderProfile
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $provider,
        public readonly string $providerUserId,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $name,
        public readonly ?string $avatarUrl,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $raw
     */
    public static function make(
        string $provider,
        string $providerUserId,
        ?string $email,
        ?string $name = null,
        ?string $avatarUrl = null,
        array $raw = [],
    ): self {
        $clean = self::stringKeyed($raw);

        return new self(
            provider: $provider,
            providerUserId: $providerUserId,
            email: $email,
            emailVerified: self::verifiedIn($clean),
            name: $name,
            avatarUrl: $avatarUrl,
            raw: self::withoutTokens($clean),
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $raw): array
    {
        $clean = [];

        foreach ($raw as $key => $value) {
            $clean[(string) $key] = $value;
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private static function verifiedIn(array $raw): bool
    {
        return filter_var($raw['email_verified'] ?? $raw['verified_email'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function withoutTokens(array $raw): array
    {
        foreach (['access_token', 'refresh_token', 'id_token', 'token'] as $key) {
            unset($raw[$key]);
        }

        return $raw;
    }
}
