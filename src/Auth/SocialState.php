<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Auth;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Config;

/**
 * What binds a provider round trip to the browser that started it, and to
 * what that browser meant by it.
 *
 * Stateless mode in Socialite means no state parameter at all, so the nonce
 * is minted and checked here. The actor is recorded at mint time because
 * that is the only moment the intent is known: begun by nobody is a sign-in,
 * begun by somebody is a link to an existing account.
 *
 * Keyed by hash, so a cache dump holds no usable states.
 */
class SocialState
{
    private const PREFIX = 'platform:social:state:';

    public function __construct(private Repository $cache) {}

    public function issue(string $provider, ?string $actorId = null): string
    {
        $state = bin2hex(random_bytes(32));

        $this->cache->put(self::key($state), [
            'provider' => $provider,
            'actor_id' => $actorId,
        ], now()->addMinutes(Config::integer('platform.auth.social.state_ttl', 10)));

        return $state;
    }

    /**
     * Single use: taken on first read, so a code replayed with the same
     * state finds nothing.
     *
     * @return array{provider: string, actor_id: string|null}|null
     */
    public function claim(string $state): ?array
    {
        $key = self::key($state);
        $claim = $this->cache->get($key);
        $this->cache->forget($key);

        if (! is_array($claim) || ! is_string($claim['provider'] ?? null)) {
            return null;
        }

        $actorId = $claim['actor_id'] ?? null;

        return [
            'provider' => $claim['provider'],
            'actor_id' => is_string($actorId) ? $actorId : null,
        ];
    }

    private static function key(string $state): string
    {
        return self::PREFIX.hash('sha256', $state);
    }
}
