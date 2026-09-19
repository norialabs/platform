<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Db;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Takes a dump, puts it on the configured disk, and keeps the last so many.
 *
 * The dump is written to a local temp file first even when the disk is
 * remote, because pg_dump writes to a path and not to a stream, and a
 * half-uploaded object is worse than no object.
 */
class Backup
{
    public function __construct(private DumperFactory $dumpers) {}

    /** @return string the path on the disk */
    public function run(?string $connection = null): string
    {
        $connection ??= Config::string('database.default');
        /** @var array<string, mixed> $settings */
        $settings = Config::array('database.connections.'.$connection);
        $driver = is_string($settings['driver'] ?? null) ? $settings['driver'] : '';

        $dumper = $this->dumpers->make($driver);
        $name = $this->name($connection, $dumper->extension());

        $local = tempnam(sys_get_temp_dir(), 'platform-backup-').'.'.$dumper->extension();

        try {
            $dumper->dump($settings, $local);

            $handle = fopen($local, 'r');

            if ($handle === false) {
                throw new RuntimeException('The dump could not be read back.');
            }

            $this->disk()->put($this->path($name), $handle);
        } finally {
            $this->forget($local);
        }

        $this->prune();

        return $this->path($name);
    }

    /** @return list<string> newest first */
    public function all(): array
    {
        $files = $this->disk()->files(Config::string('platform.db.path', 'backups'));

        rsort($files);

        return $files;
    }

    /** @return list<string> what was removed */
    public function prune(): array
    {
        $keep = Config::integer('platform.db.keep', 14);

        if ($keep <= 0) {
            return [];
        }

        $stale = array_slice($this->all(), $keep);

        foreach ($stale as $file) {
            $this->disk()->delete($file);
        }

        return $stale;
    }

    public function disk(): Filesystem
    {
        return Storage::disk(Config::string('platform.db.disk', 'local'));
    }

    public function path(string $name): string
    {
        return trim(Config::string('platform.db.path', 'backups'), '/').'/'.$name;
    }

    /** Sorts lexically into chronological order, which is what prune relies on. */
    private function name(string $connection, string $extension): string
    {
        $database = DB::connection($connection)->getDatabaseName();
        $stamp = Carbon::now()->format('Y-m-d-His');

        return "{$database}-{$stamp}.{$extension}";
    }

    private function forget(string $path): void
    {
        foreach ([$path, preg_replace('/\.gz$/', '', $path)] as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                unlink($candidate);
            }
        }
    }
}
