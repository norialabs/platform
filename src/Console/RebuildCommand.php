<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use NoriaLabs\Platform\Db\Rebuild;

/**
 * Migrations edited in place leave a long-lived database behind: migrate
 * sees every file already run, and the gap surfaces later as a missing
 * relation. This rebuilds the schema from the files and puts the rows back.
 */
class RebuildCommand extends Command
{
    protected $signature = 'platform:rebuild
        {--copy= : The name for the copy taken first}
        {--keep : Keep the copy and the dump afterwards}
        {--bring-up=* : Commands to run against the reloaded rows}
        {--force : Allow this to run in production}';

    protected $description = 'Rebuild the schema from the migrations and reload the existing rows';

    public function handle(Rebuild $rebuild): int
    {
        if (App::isProduction() && ! $this->option('force')) {
            $this->components->error('Refusing to rebuild production without an explicit force.');

            return self::FAILURE;
        }

        $copy = $this->option('copy');
        $copy = is_string($copy) && $copy !== '' ? $copy : 'rebuild_copy_'.now()->utc()->format('Ymd_His');

        /** @var list<string> $bringUp */
        $bringUp = (array) $this->option('bring-up');

        $result = $rebuild->run(
            $copy,
            (bool) $this->option('keep'),
            fn (string $line) => $this->components->task($line, fn (): bool => true),
            $bringUp,
        );

        $this->components->twoColumnDetail('Tables', (string) $result['tables']);
        $this->components->twoColumnDetail('Rows', (string) $result['rows']);
        $this->components->twoColumnDetail('Copy', $result['dump'] === null ? 'dropped' : $result['copy']);

        return self::SUCCESS;
    }
}
