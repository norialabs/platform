<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

interface DatabaseDumper
{
    public function driver(): string;

    public function extension(): string;

    /**
     * @param  array<string, mixed>  $connection  a config('database.connections.*') array
     */
    public function dump(array $connection, string $destination): void;

    /**
     * @param  array<string, mixed>  $connection
     */
    public function restore(array $connection, string $source): void;
}
