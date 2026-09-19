<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Db;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use NoriaLabs\Platform\Log\Logger;
use RuntimeException;
use Throwable;

/**
 * Takes a dump, puts it on the configured disk, promotes the first one
 * after the daily boundary, and prunes each tier to its own retention.
 *
 * Written to a local file first even when the disk is remote, because the
 * tools write to a path and not to a stream, and a half-uploaded object is
 * worse than no object.
 */
class Backup
{
    public function __construct(private DumperFactory $dumpers) {}

    /** @return array{key: string, disk: string, tier: BackupTier, bytes: int, pruned: int} */
    public function run(?string $disk = null, ?BackupTier $tier = null, ?string $connection = null): array
    {
        $disk ??= Config::string('noria.db.disk', 'local');

        $settings = Connections::asAdmin(Connections::settings($connection));
        $dumper = $this->dumpers->make(Connections::driver($settings));

        $tier ??= $this->dueTier($disk);
        $path = $this->localPath($settings, $dumper->extension());

        try {
            $dumper->dump($settings, $path);

            $bytes = (int) @filesize($path);

            // A dump of nothing is the shape an unprivileged role produces,
            // and the preflight does not catch every way of getting there.
            if ($bytes === 0) {
                throw new RuntimeException('The dump is empty.');
            }

            $key = BackupTier::Hourly->prefix().'/'.basename($path);
            $this->upload($disk, $key, $path);

            if ($tier === BackupTier::Daily) {
                $key = $this->promote($disk, $key);
            }

            return [
                'key' => $key,
                'disk' => $disk,
                'tier' => $tier,
                'bytes' => $bytes,
                'pruned' => $this->prune($disk, BackupTier::Hourly) + $this->prune($disk, BackupTier::Daily),
            ];
        } finally {
            $this->forget($path);
        }
    }

    /** The newest dump across both tiers. */
    public function latestKey(?string $disk = null): string
    {
        $disk ??= Config::string('noria.db.disk', 'local');
        $files = [];

        foreach (BackupTier::cases() as $tier) {
            $files = [...$files, ...$this->filesIn($disk, $tier)];
        }

        $dated = array_values(array_filter($files, fn (string $file): bool => self::takenAt($file) !== null));

        if ($dated === []) {
            throw new RuntimeException(
                "Disk [{$disk}] holds no dump to restore. Name a key explicitly, or take a backup first."
            );
        }

        usort($dated, fn (string $a, string $b): int => self::takenAt($a) <=> self::takenAt($b));

        return (string) end($dated);
    }

    /** @return list<string> newest first */
    public function all(?string $disk = null, ?BackupTier $tier = null): array
    {
        $disk ??= Config::string('noria.db.disk', 'local');
        $tiers = $tier === null ? BackupTier::cases() : [$tier];
        $files = [];

        foreach ($tiers as $each) {
            $files = [...$files, ...array_values(array_filter($this->disk($disk)->files($each->prefix()), is_string(...)))];
        }

        rsort($files);

        return $files;
    }

    /** @return int how many were removed */
    public function prune(string $disk, BackupTier $tier): int
    {
        $cutoff = Carbon::now('UTC')->subHours($tier->retentionHours());

        try {
            $stale = array_values(array_filter(
                $this->filesIn($disk, $tier),
                fn (string $file): bool => self::takenAt($file)?->lt($cutoff) === true,
            ));

            if ($stale === []) {
                return 0;
            }

            $this->overNetwork(fn (): bool => $this->disk($disk)->delete($stale));

            return count($stale);
        } catch (Throwable $e) {
            // The dump is already safe. Failing the whole run because the
            // sweep failed would turn a storage bill into a missing backup.
            Logger::exception('backup uploaded but could not prune older dumps', $e, ['tier' => $tier->value]);

            return 0;
        }
    }

    public function disk(?string $disk = null): Filesystem
    {
        return Storage::disk($disk ?? Config::string('noria.db.disk', 'local'));
    }

    /**
     * The first dump taken after the daily hour is the one kept for a
     * month; every other dump that day is hourly.
     */
    private function dueTier(string $disk): BackupTier
    {
        $boundary = Carbon::now('UTC')->startOfDay()
            ->addHours(max(0, min(23, Config::integer('noria.db.tiers.daily.hour', 0))));

        if (Carbon::now('UTC')->lt($boundary)) {
            return BackupTier::Hourly;
        }

        try {
            $taken = $this->filesIn($disk, BackupTier::Daily);
        } catch (Throwable $e) {
            Logger::exception('backup could not read the daily tier; taking an hourly dump', $e);

            return BackupTier::Hourly;
        }

        foreach ($taken as $file) {
            if (self::takenAt($file)?->gte($boundary) === true) {
                return BackupTier::Hourly;
            }
        }

        return BackupTier::Daily;
    }

    private function promote(string $disk, string $key): string
    {
        $daily = BackupTier::Daily->prefix().'/'.basename($key);

        $this->overNetwork(function () use ($disk, $key, $daily): void {
            if ($this->disk($disk)->copy($key, $daily) === false) {
                throw new RuntimeException("Disk [{$disk}] did not accept the copy to [{$daily}].");
            }
        });

        return $daily;
    }

    private function upload(string $disk, string $key, string $path): void
    {
        $this->overNetwork(function () use ($disk, $key, $path): void {
            $stream = @fopen($path, 'rb');

            if ($stream === false) {
                throw new RuntimeException("Unable to read the dump at {$path}.");
            }

            try {
                if ($this->disk($disk)->put($key, $stream) === false) {
                    throw new RuntimeException(
                        "Disk [{$disk}] did not accept the upload to [{$key}] after ".$this->attempts().' attempts. '
                        ."The reason is suppressed by filesystems.disks.{$disk}.throw; a connection failure to the "
                        .'endpoint looks identical to a rejection here.'
                    );
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        });
    }

    /**
     * The files in one tier, retried, and narrowed to what they have to be
     * before anything downstream trusts them.
     *
     * @return list<string>
     */
    private function filesIn(string $disk, BackupTier $tier): array
    {
        $files = $this->overNetwork(fn (): array => $this->disk($disk)->files($tier->prefix()));

        return array_values(array_filter(is_array($files) ? $files : [], is_string(...)));
    }

    /**
     * Object storage fails in ways a local disk does not, and once the disk
     * swallows the reason a failed upload looks identical to a rejected one.
     *
     * @param  Closure(): mixed  $work
     */
    private function overNetwork(Closure $work): mixed
    {
        return retry($this->attempts(), $work, fn (int $attempt): int => $attempt * 2_000);
    }

    private function attempts(): int
    {
        return max(1, Config::integer('noria.db.attempts', 3));
    }

    /** @param array<string, mixed> $settings */
    private function localPath(array $settings, string $extension): string
    {
        $directory = $this->workingDirectory();

        $database = preg_replace('/[^A-Za-z0-9_-]/', '_', Connections::value($settings, 'database', 'database'));
        $database = is_string($database) && $database !== '' ? $database : 'database';

        // Named so it sorts chronologically and takenAt can read it back:
        // the tier sweep has no other way to know a file's age.
        $stamp = Carbon::now('UTC')->format('Ymd-His');
        $suffix = substr(bin2hex(random_bytes(3)), 0, 6);

        return $directory."/{$stamp}-{$suffix}-{$database}.{$extension}";
    }

    public function workingDirectory(): string
    {
        $configured = Config::get('noria.db.working_directory');
        $directory = is_string($configured) && $configured !== ''
            ? $configured
            : sys_get_temp_dir().'/noria-backup';

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create the backup working directory at {$directory}.");
        }

        return $directory;
    }

    public static function takenAt(string $file): ?Carbon
    {
        if (preg_match('/^(\d{8})-(\d{6})-/', basename($file), $matches) !== 1) {
            return null;
        }

        $stamp = Carbon::createFromFormat('Ymd His', $matches[1].' '.$matches[2], 'UTC');

        return $stamp instanceof Carbon ? $stamp : null;
    }

    private function forget(string $path): void
    {
        foreach ([$path, preg_replace('/\.gz$/', '', $path)] as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                @unlink($candidate);
            }
        }
    }
}
