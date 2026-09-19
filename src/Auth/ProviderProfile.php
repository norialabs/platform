<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Auth;

/**
 * What a provider told us about the person who just consented.
 *
 * Socialite's own user object stops at the edge of the application: the
 * linking rules turn on whether the provider says the email is verified,
 * and that flag lives in the raw payload under a different name for every
 * provider.
 */
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
     * Built from Socialite's user without depending on the package: a host
     * passes what it got, and products that do not use Socialite at all do
     * not gain a dependency on it.
     *
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
     * Google sends email_verified on the id token and verified_email on the
     * older userinfo shape. Absent means not verified: a provider that does
     * not say has not said yes.
     *
     * @param  array<string, mixed>  $raw
     */
    private static function verifiedIn(array $raw): bool
    {
        return filter_var($raw['email_verified'] ?? $raw['verified_email'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * A debugging aid, not a credential store: no provider access or refresh
     * token is kept. A feature that needs to call a provider API gets an
     * encrypted column of its own.
     *
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
