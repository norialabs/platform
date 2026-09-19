<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * The scheduler container's healthcheck. A scheduler that is running but
 * never firing looks identical to a healthy one from outside, so the
 * heartbeat is written by a scheduled task and read by this.
 */
class SchedulerHealthyCommand extends Command
{
    protected $signature = 'platform:scheduler-healthy';

    protected $description = 'Exit non-zero when the scheduler has stopped firing';

    public const KEY = 'platform:scheduler:heartbeat';

    public function handle(): int
    {
        $beat = Cache::get(self::KEY);

        if ($beat === null) {
            $this->components->error('The scheduler has not checked in.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
