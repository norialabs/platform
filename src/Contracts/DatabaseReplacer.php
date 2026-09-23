<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

interface DatabaseReplacer
{
    /**
     * @param  array<string, mixed>  $connection
     * @return int how many tables were there before
     */
    public function replace(array $connection, string $source): int;
}
