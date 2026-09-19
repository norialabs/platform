<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

interface DatabaseDumper
{
    public function driver(): string;

    public function extension(): string;

    /**
     * Write the database described by $connection to $destination.
     *
     * @param  array<string, mixed>  $connection  a config('database.connections.*') array
     */
    public function dump(array $connection, string $destination): void;

    /**
     * Read $source back into the database described by $connection. The
     * caller has already decided this is allowed.
     *
     * @param  array<string, mixed>  $connection
     */
    public function restore(array $connection, string $source): void;
}
