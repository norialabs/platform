<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Db\Dumpers;

use NoriaLabs\Platform\Contracts\DatabaseDumper;
use RuntimeException;

/**
 * A copy of the file. There is no server to ask, and sqlite3 .dump would
 * turn a binary file that restores instantly into text that has to be
 * replayed.
 */
class SqliteDumper implements DatabaseDumper
{
    public function driver(): string
    {
        return 'sqlite';
    }

    public function extension(): string
    {
        return 'sqlite';
    }

    /** @param array<string, mixed> $connection */
    public function dump(array $connection, string $destination): void
    {
        $this->copy($this->path($connection), $destination);
    }

    /** @param array<string, mixed> $connection */
    public function restore(array $connection, string $source): void
    {
        $this->copy($source, $this->path($connection));
    }

    private function copy(string $from, string $to): void
    {
        if (! is_file($from) || ! copy($from, $to)) {
            throw new RuntimeException("Could not copy [{$from}] to [{$to}].");
        }
    }

    /** @param array<string, mixed> $connection */
    private function path(array $connection): string
    {
        $database = $connection['database'] ?? null;

        if (! is_string($database) || $database === '' || $database === ':memory:') {
            throw new RuntimeException('An in-memory database cannot be backed up.');
        }

        return $database;
    }
}
