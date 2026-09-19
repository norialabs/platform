<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

/** Scheduled every minute; the healthcheck reads what it leaves behind. */
class SchedulerHeartbeatCommand extends Command
{
    protected $signature = 'noria:scheduler-heartbeat';

    protected $description = 'Record that the scheduler is still firing';

    public function handle(): int
    {
        // Longer than the interval it is scheduled at, so one slow minute is
        // not an outage, and short enough that a stopped scheduler is caught.
        Cache::put(
            SchedulerHealthyCommand::KEY,
            now()->toIso8601String(),
            Config::integer('noria.scheduler.heartbeat_ttl', 300),
        );

        return self::SUCCESS;
    }
}
