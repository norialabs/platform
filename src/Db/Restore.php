<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Db;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Reads a dump back over a database.
 *
 * Refuses in production unless the caller says so out loud, because the
 * command that restores last night's data over today's is the same command
 * either way.
 */
class Restore
{
    public function __construct(
        private DumperFactory $dumpers,
        private Backup $backups,
    ) {}

    public function run(string $path, ?string $connection = null, bool $force = false): void
    {
        if (App::isProduction() && ! $force) {
            throw new RuntimeException('Refusing to restore over production without an explicit force.');
        }

        $connection ??= Config::string('database.default');
        /** @var array<string, mixed> $settings */
        $settings = Config::array('database.connections.'.$connection);
        $driver = is_string($settings['driver'] ?? null) ? $settings['driver'] : '';

        $disk = $this->backups->disk();

        if (! $disk->exists($path)) {
            throw new RuntimeException("No backup at [{$path}].");
        }

        $local = tempnam(sys_get_temp_dir(), 'platform-restore-').'.'.pathinfo($path, PATHINFO_EXTENSION);
        $stream = $disk->readStream($path);

        if ($stream === null) {
            throw new RuntimeException("The backup at [{$path}] could not be read.");
        }

        $target = fopen($local, 'w');

        if ($target === false) {
            throw new RuntimeException('A local copy of the backup could not be written.');
        }

        stream_copy_to_stream($stream, $target);
        fclose($target);
        fclose($stream);

        try {
            $this->dumpers->make($driver)->restore($settings, $local);
        } finally {
            foreach ([$local, preg_replace('/\.gz$/', '', $local)] as $candidate) {
                if (is_string($candidate) && is_file($candidate)) {
                    unlink($candidate);
                }
            }
        }
    }
}
