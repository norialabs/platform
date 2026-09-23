<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Db;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use NoriaLabs\Platform\Contracts\DatabaseMaintainer;
use NoriaLabs\Platform\Contracts\DatabaseReplacer;
use RuntimeException;

class Restore
{
    public function __construct(
        private DumperFactory $dumpers,
        private Backup $backups,
    ) {}

    /** @return array{key: string, disk: string, database: string, created: bool, bytes: int, dropped: int, extensions: list<string>} */
    public function run(
        ?string $key = null,
        ?string $disk = null,
        ?string $database = null,
        ?string $connection = null,
        bool $force = false,
    ): array {
        if (App::isProduction() && ! $force) {
            throw new RuntimeException('Refusing to restore over production without an explicit force.');
        }

        $disk ??= Config::string('noria.db.disk', 'local');

        $settings = Connections::settings($connection);
        $appRole = Connections::value($settings, 'username');
        $settings = Connections::asAdmin($settings);

        $dumper = $this->dumpers->make(Connections::driver($settings));
        $created = false;

        if ($database !== null && $database !== Connections::value($settings, 'database')) {
            if (! $dumper instanceof DatabaseMaintainer) {
                throw new RuntimeException('This driver cannot restore into a database of its own.');
            }

            $created = $dumper->ensureDatabase($settings, $database, $appRole);
            $settings['database'] = $database;

            if ($created) {
                $dumper->grantSchema($settings, $appRole);
            }
        }

        $key ??= $this->backups->latestKey($disk);

        $stamp = substr(bin2hex(random_bytes(3)), 0, 6);
        $archive = $this->backups->workingDirectory()."/restore-{$stamp}.sql.gz";
        $plain = $this->backups->workingDirectory()."/restore-{$stamp}.sql";

        try {
            if (str_ends_with($key, Backup::SEALED)) {
                $bytes = $this->download($disk, $key, $archive.Backup::SEALED);
                $this->backups->unseal($archive.Backup::SEALED, $archive);
            } else {
                $bytes = $this->download($disk, $key, $archive);
            }

            $source = $this->expand($archive, $plain);

            $extensions = $dumper instanceof DatabaseMaintainer ? $dumper->ensureExtensions($settings, $source) : [];
            if ($dumper instanceof DatabaseReplacer) {
                $dropped = $dumper->replace($settings, $source);
            } else {
                $dropped = $dumper instanceof DatabaseMaintainer ? $dumper->dropExisting($settings) : 0;
                $dumper->restore($settings, $source);
            }

            return [
                'key' => $key,
                'disk' => $disk,
                'database' => Connections::value($settings, 'database'),
                'created' => $created,
                'bytes' => $bytes,
                'dropped' => $dropped,
                'extensions' => $extensions,
            ];
        } finally {
            foreach ([$archive.Backup::SEALED, $archive, $plain] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    private function download(string $disk, string $key, string $destination): int
    {
        $filesystem = $this->backups->disk($disk);

        if (! $filesystem->exists($key)) {
            throw new RuntimeException("Disk [{$disk}] has no object at [{$key}].");
        }

        $source = $filesystem->readStream($key);

        if (! is_resource($source)) {
            throw new RuntimeException("Disk [{$disk}] would not open [{$key}] for reading.");
        }

        $target = @fopen($destination, 'wb');

        if ($target === false) {
            fclose($source);

            throw new RuntimeException("Unable to write the download to {$destination}.");
        }

        try {
            stream_copy_to_stream($source, $target);
        } finally {
            fclose($source);
            fclose($target);
        }

        $bytes = (int) @filesize($destination);

        if ($bytes === 0) {
            throw new RuntimeException("The dump at [{$key}] is empty.");
        }

        return $bytes;
    }

    /** @return string the path the engine should read, expanded where it was compressed */
    private function expand(string $archive, string $destination): string
    {
        if (! str_ends_with($archive, '.gz')) {
            return $archive;
        }

        $source = @gzopen($archive, 'rb');

        if ($source === false) {
            throw new RuntimeException("Unable to read the dump at {$archive}.");
        }

        $target = @fopen($destination, 'wb');

        if ($target === false) {
            gzclose($source);

            throw new RuntimeException("Unable to write the expanded dump to {$destination}.");
        }

        try {
            while (! gzeof($source)) {
                $chunk = gzread($source, 262_144);

                if ($chunk === false) {
                    throw new RuntimeException("Unable to expand the dump at {$archive}.");
                }

                fwrite($target, $chunk);
            }
        } finally {
            gzclose($source);
            fclose($target);
        }

        return $destination;
    }
}
