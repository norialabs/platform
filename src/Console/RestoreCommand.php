<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Console;

use Illuminate\Console\Command;
use NoriaLabs\Platform\Db\Backup;
use NoriaLabs\Platform\Db\Restore;
use RuntimeException;

class RestoreCommand extends Command
{
    protected $signature = 'noria:restore
        {key? : The backup to read, newest across both tiers when omitted}
        {--disk= : The filesystem disk to read from}
        {--database= : Restore into this database instead, creating it if needed}
        {--connection= : The database connection to restore into}
        {--force : Allow this to run in production, and skip the prompt}';

    protected $description = 'Read a backup back over a database, or beside it';

    public function handle(Backup $backups, Restore $restore): int
    {
        $key = $this->argument('key');
        $key = is_string($key) && $key !== '' ? $key : null;
        $into = $this->text('database');

        if ($key === null) {
            try {
                $key = $backups->latestKey($this->text('disk'));
            } catch (RuntimeException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }
        }

        if (! $this->option('force') && ! $this->confirm('Restore over '.($into ?? 'the current database').'?', false)) {
            return self::FAILURE;
        }

        $result = $restore->run(
            $key,
            $this->text('disk'),
            $into,
            $this->text('connection'),
            (bool) $this->option('force'),
        );

        $this->components->twoColumnDetail('Key', $result['key']);
        $this->components->twoColumnDetail('Database', $result['database'].($result['created'] ? ' (created)' : ''));
        $this->components->twoColumnDetail('Dropped', (string) $result['dropped'].' tables');

        if ($result['extensions'] !== []) {
            $this->components->twoColumnDetail('Installed', implode(', ', $result['extensions']));
        }
        $this->components->twoColumnDetail('Size', sprintf('%.1f MB', $result['bytes'] / 1_048_576));

        return self::SUCCESS;
    }

    private function text(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
