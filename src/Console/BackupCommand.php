<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Console;

use Illuminate\Console\Command;
use NoriaLabs\Platform\Db\Backup;

class BackupCommand extends Command
{
    protected $signature = 'platform:backup {--connection= : The database connection to dump}';

    protected $description = 'Dump the database to the configured disk and prune old backups';

    public function handle(Backup $backups): int
    {
        $connection = $this->option('connection');

        $path = $backups->run(is_string($connection) && $connection !== '' ? $connection : null);

        $this->components->info('Backup written to '.$path);

        return self::SUCCESS;
    }
}
