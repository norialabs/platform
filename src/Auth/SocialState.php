<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Auth;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Config;

class SocialState
{
    private const PREFIX = 'noria:social:state:';

    public function __construct(private Repository $cache) {}

    public function issue(string $provider, ?string $actorId = null): string
    {
        $state = bin2hex(random_bytes(32));

        $this->cache->put(self::key($state), [
            'provider' => $provider,
            'actor_id' => $actorId,
        ], now()->addMinutes(Config::integer('noria.auth.social.state_ttl', 10)));

        return $state;
    }

    /**
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
