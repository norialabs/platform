<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Db\Dumpers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use NoriaLabs\Platform\Contracts\DatabaseDumper;
use Symfony\Component\Process\Process;

class PostgresDumper implements DatabaseDumper
{
    public function driver(): string
    {
        return 'pgsql';
    }

    public function extension(): string
    {
        return $this->gzip() ? 'sql.gz' : 'sql';
    }

    /** @param array<string, mixed> $connection */
    public function dump(array $connection, string $destination): void
    {
        $sql = $this->withoutGzipSuffix($destination);

        $this->run([
            'pg_dump',
            '--host='.$this->value($connection, 'host', '127.0.0.1'),
            '--port='.$this->value($connection, 'port', '5432'),
            '--username='.$this->value($connection, 'username', ''),
            '--dbname='.$this->value($connection, 'database', ''),
            '--no-owner',
            '--no-privileges',
            '--format=plain',
            '--file='.$sql,
        ], $connection);

        if ($this->gzip()) {
            $this->run(['gzip', '--force', $sql], $connection);
        }
    }

    /** @param array<string, mixed> $connection */
    public function restore(array $connection, string $source): void
    {
        $sql = $this->withoutGzipSuffix($source);

        if ($source !== $sql) {
            $this->run(['gunzip', '--force', '--keep', $source], $connection);
        }

        // ON_ERROR_STOP, because psql's default is to report a failed
        // statement and carry on, leaving a database that restored
        // "successfully" and is missing a table.
        $this->run([
            'psql',
            '--host='.$this->value($connection, 'host', '127.0.0.1'),
            '--port='.$this->value($connection, 'port', '5432'),
            '--username='.$this->value($connection, 'username', ''),
            '--dbname='.$this->value($connection, 'database', ''),
            '--set=ON_ERROR_STOP=1',
            '--quiet',
            '--file='.$sql,
        ], $connection);
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, mixed>  $connection
     */
    private function run(array $command, array $connection): void
    {
        $password = $this->value($connection, 'password', '');
        $env = $password === '' ? [] : ['PGPASSWORD' => $password];

        (new Process($command, env: $env, timeout: Config::integer('platform.db.timeout', 900)))->mustRun();
    }

    private function gzip(): bool
    {
        return Config::boolean('platform.db.gzip', true);
    }

    private function withoutGzipSuffix(string $path): string
    {
        return Str::replaceEnd('.gz', '', $path);
    }

    /** @param array<string, mixed> $connection */
    private function value(array $connection, string $key, string $default): string
    {
        $value = $connection[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }
}
