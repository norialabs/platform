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

class Backup
{
    public const SEALED = '.enc';

    private const CHUNK = 1_048_576;

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

            if ($bytes === 0) {
                throw new RuntimeException('The dump is empty.');
            }

            $sealed = $this->seal($path);
            $key = BackupTier::Hourly->prefix().'/'.basename($sealed);
            $this->upload($disk, $key, $sealed);

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
            Logger::exception('backup uploaded but could not prune older dumps', $e, ['tier' => $tier->value]);

            return 0;
        }
    }

    public function disk(?string $disk = null): Filesystem
    {
        return Storage::disk($disk ?? Config::string('noria.db.disk', 'local'));
    }

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
     * @return list<string>
     */
    private function filesIn(string $disk, BackupTier $tier): array
    {
        $files = $this->overNetwork(fn (): array => $this->disk($disk)->files($tier->prefix()));

        return array_values(array_filter(is_array($files) ? $files : [], is_string(...)));
    }

    /**
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

        $stamp = Carbon::now('UTC')->format('Ymd-His');
        $suffix = substr(bin2hex(random_bytes(3)), 0, 6);

        return $directory."/{$stamp}-{$suffix}-{$database}.{$extension}";
    }

    public function seal(string $path): string
    {
        $key = $this->encryptionKey();

        if ($key === null) {
            return $path;
        }

        $sealed = $path.self::SEALED;
        [$source, $target] = $this->openPair($path, $sealed);

        try {
            /** @var array{0: string, 1: string} $pushed */
            $pushed = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            $state = $pushed[0];
            fwrite($target, $pushed[1]);

            do {
                $chunk = (string) fread($source, self::CHUNK);
                $last = feof($source);
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    $chunk,
                    '',
                    $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                );
                fwrite($target, pack('N', strlen($cipher)).$cipher);
            } while (! $last);
        } finally {
            fclose($source);
            fclose($target);
        }

        return $sealed;
    }

    public function unseal(string $sealed, string $destination): void
    {
        $key = $this->encryptionKey()
            ?? throw new RuntimeException('This dump is encrypted and noria.db.encryption_key is not set.');

        [$source, $target] = $this->openPair($sealed, $destination);

        try {
            $header = (string) fread($source, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);

            if (strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new RuntimeException("The encrypted dump at {$sealed} is truncated.");
            }

            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $finished = false;

            while (! $finished) {
                $length = unpack('N', (string) fread($source, 4));
                $size = is_array($length) && is_int($length[1]) ? $length[1] : 0;
                $cipher = $size > 0 ? (string) fread($source, $size) : '';
                /** @var array{0: string, 1: int}|false $opened */
                $opened = $cipher === '' ? false : sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);

                if ($opened === false) {
                    throw new RuntimeException("The encrypted dump at {$sealed} is truncated, tampered with, or was sealed with another key.");
                }

                fwrite($target, $opened[0]);
                $finished = $opened[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
        } finally {
            fclose($source);
            fclose($target);
        }
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

    private function encryptionKey(): ?string
    {
        $configured = Config::get('noria.db.encryption_key');

        if (! is_string($configured) || $configured === '') {
            return null;
        }

        $key = str_starts_with($configured, 'base64:') ? base64_decode(substr($configured, 7), true) : $configured;

        if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('noria.db.encryption_key must be 32 bytes, written as base64:<key>.');
        }

        return $key;
    }

    /** @return array{0: resource, 1: resource} */
    private function openPair(string $from, string $to): array
    {
        $source = @fopen($from, 'rb');

        if ($source === false) {
            throw new RuntimeException("Unable to read {$from}.");
        }

        $target = @fopen($to, 'wb');

        if ($target === false) {
            fclose($source);

            throw new RuntimeException("Unable to write {$to}.");
        }

        return [$source, $target];
    }

    private function forget(string $path): void
    {
        foreach ([$path, $path.self::SEALED, preg_replace('/\.gz$/', '', $path)] as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                @unlink($candidate);
            }
        }
    }
}
