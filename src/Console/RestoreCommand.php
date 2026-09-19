<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Console;

use Illuminate\Console\Command;
use NoriaLabs\Platform\Db\Backup;
use NoriaLabs\Platform\Db\Restore;

class RestoreCommand extends Command
{
    protected $signature = 'platform:restore
        {path? : The backup to read, newest when omitted}
        {--connection= : The database connection to restore into}
        {--force : Allow this to run in production}';

    protected $description = 'Read a backup back over a database';

    public function handle(Backup $backups, Restore $restore): int
    {
        $path = $this->argument('path');

        if (! is_string($path) || $path === '') {
            $path = $backups->all()[0] ?? null;
        }

        if (! is_string($path)) {
            $this->components->error('There are no backups to restore.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Restore {$path} over the database?", false)) {
            return self::FAILURE;
        }

        $connection = $this->option('connection');

        $restore->run($path, is_string($connection) && $connection !== '' ? $connection : null, (bool) $this->option('force'));

        $this->components->info('Restored from '.$path);

        return self::SUCCESS;
    }
}
