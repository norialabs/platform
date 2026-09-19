<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Console;

use Illuminate\Console\Command;
use NoriaLabs\Platform\Db\Backup;
use NoriaLabs\Platform\Db\BackupTier;

class BackupCommand extends Command
{
    protected $signature = 'platform:backup
        {--disk= : The filesystem disk to upload to}
        {--tier= : Force hourly or daily rather than letting the clock decide}
        {--connection= : The database connection to dump}';

    protected $description = 'Dump the database to the configured disk and prune older backups';

    public function handle(Backup $backups): int
    {
        $tier = $this->option('tier');

        $result = $backups->run(
            $this->text('disk'),
            is_string($tier) && $tier !== '' ? BackupTier::from($tier) : null,
            $this->text('connection'),
        );

        $this->components->twoColumnDetail('Key', $result['key']);
        $this->components->twoColumnDetail('Tier', $result['tier']->value);
        $this->components->twoColumnDetail('Size', sprintf('%.1f MB', $result['bytes'] / 1_048_576));
        $this->components->twoColumnDetail('Pruned', (string) $result['pruned']);

        return self::SUCCESS;
    }

    private function text(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
