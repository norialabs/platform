<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Console;

use Illuminate\Console\Command;
use NoriaLabs\Platform\Tenancy\Invariants;

/**
 * The deployment check, not a request check: run it at boot, on a clock, and
 * in CI against a migrated database.
 */
class TenancyCheckCommand extends Command
{
    protected $signature = 'noria:tenancy-check';

    protected $description = 'Report any way one workspace could read another';

    public function handle(): int
    {
        $failures = Invariants::all();

        if ($failures === []) {
            $this->components->info('Tenant isolation is enforced.');

            return self::SUCCESS;
        }

        foreach ($failures as $group => $items) {
            foreach ($items as $item) {
                $this->components->twoColumnDetail(str_replace('_', ' ', $group), $item);
            }
        }

        $this->components->error('Tenant isolation is not enforced.');

        return self::FAILURE;
    }
}
