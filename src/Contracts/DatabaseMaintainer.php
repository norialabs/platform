<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

interface DatabaseMaintainer
{
    /**
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

    /**
     * @param  array<string, mixed>  $connection  pointed at the database being loaded
     * @param  string  $source  the plain dump about to be read
     * @return list<string> what had to be installed, empty where the target already had them
     */
    public function ensureExtensions(array $connection, string $source): array;
}
