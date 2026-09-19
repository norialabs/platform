<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

/**
 * The parts of a restore only some engines can do: making the target
 * database, granting the application role into it, and emptying it first.
 *
 * Separate from DatabaseDumper because a sqlite dumper does none of them
 * and should not have to pretend.
 */
interface DatabaseMaintainer
{
    /**
     * Refuse now rather than write a file that restores to nothing.
     *
     * @param  array<string, mixed>  $connection
     */
    public function preflight(array $connection): void;

    /**
     * @param  array<string, mixed>  $connection
     * @return bool whether it had to be created
     */
    public function ensureDatabase(array $connection, string $database, string $appRole): bool;

    /** @param array<string, mixed> $connection */
    public function grantSchema(array $connection, string $appRole): void;

    /**
     * @param  array<string, mixed>  $connection
     * @return int how many tables were there before
     */
    public function dropExisting(array $connection): int;
}
