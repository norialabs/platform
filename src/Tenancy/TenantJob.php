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

/**
 * Every queued job belongs to one workspace and runs inside it: a job with no
 * workspace set matches nothing, succeeds, and leaves the work undone.
 *
 * It carries ids and never models, because a serialised model is the row as
 * it was when the job was queued, and a queue ten minutes behind writes back
 * what was true ten minutes ago.
 *
 * Subclasses declare work(), which is resolved through the container so it
 * may type-hint whatever it needs. It is not an abstract method for that
 * reason: a fixed signature would forbid the injection, and a queued job
 * cannot take its dependencies through a constructor because they would be
 * serialised into the payload alongside the ids.
 */
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
        // Per workspace: one customer must not wait on another customer's file.
        return [
            (new WithoutOverlapping($this->lockKey()))
                ->expireAfter(Config::integer('platform.tenancy.overlap_expires_after', 3600)),
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
        return Config::string('platform.tenancy.queue', 'default');
    }

    /** What must not run twice at once. The workspace by default; narrow it where that is too wide. */
    protected function lockKey(): string
    {
        return static::class.':'.$this->workspaceId;
    }
}
