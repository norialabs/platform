<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tenancy;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use LogicException;

abstract class TenantJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $workspaceId)
    {
        $this->onQueue($this->lane());
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->lockKey()))
                ->expireAfter(Config::integer('noria.tenancy.overlap_expires_after', 3600)),
        ];
    }

    public function handle(Tenancy $tenancy, Container $container): void
    {
        if (! method_exists($this, 'work')) {
            throw new LogicException(static::class.' must declare work().');
        }

        $tenancy->run($this->workspaceId, fn () => $container->call([$this, 'work']));
    }

    protected function lane(): string
    {
        return Config::string('noria.tenancy.queue', 'default');
    }

    protected function lockKey(): string
    {
        return static::class.':'.$this->workspaceId;
    }
}
