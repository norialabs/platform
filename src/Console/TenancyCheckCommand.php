<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Console;

use Illuminate\Console\Command;
use NoriaLabs\Platform\Db\Timestamps;
use NoriaLabs\Platform\Tenancy\Invariants;
use Throwable;

/**
 * The deployment check, not a request check: run it at boot, on a clock,
 * and in CI against a migrated database.
 *
 * Everything it reports is something that is quietly wrong rather than
 * broken - a policy nobody applied, a connection storing every moment at
 * the wrong instant. None of it surfaces as an error on its own.
 */
class TenancyCheckCommand extends Command
{
    protected $signature = 'noria:tenancy-check';

    protected $description = 'Report anything about this deployment that is quietly wrong';

    public function handle(): int
    {
        $failures = [...Invariants::all(), ...$this->clock()];

        if ($failures === []) {
            $this->components->info('Tenant isolation is enforced and timestamps agree with the application.');

            return self::SUCCESS;
        }

        foreach ($failures as $group => $items) {
            foreach ($items as $item) {
                $this->components->twoColumnDetail(str_replace('_', ' ', (string) $group), $item);
            }
        }

        $this->components->error('This deployment is not sound.');

        return self::FAILURE;
    }

    /**
     * Checked here as well as at migrate time, because a connection added
     * afterwards, or a config edit, would not be caught until the next
     * migration - by which point the rows are already wrong.
     *
     * @return array<string, list<string>>
     */
    private function clock(): array
    {
        try {
            Timestamps::assertAligned();
        } catch (Throwable $e) {
            return ['timestamps' => [$e->getMessage()]];
        }

        return [];
    }
}
