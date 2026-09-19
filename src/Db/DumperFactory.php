<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Db;

use Illuminate\Contracts\Container\Container;
use NoriaLabs\Platform\Contracts\DatabaseDumper;
use NoriaLabs\Platform\Db\Dumpers\MysqlDumper;
use NoriaLabs\Platform\Db\Dumpers\PostgresDumper;
use NoriaLabs\Platform\Db\Dumpers\SqliteDumper;
use RuntimeException;

class DumperFactory
{
    /** @var array<string, class-string<DatabaseDumper>> */
    private array $dumpers = [
        'pgsql' => PostgresDumper::class,
        'mysql' => MysqlDumper::class,
        'mariadb' => MysqlDumper::class,
        'sqlite' => SqliteDumper::class,
    ];

    public function __construct(private Container $container) {}

    /** @param class-string<DatabaseDumper> $dumper */
    public function register(string $driver, string $dumper): void
    {
        $this->dumpers[$driver] = $dumper;
    }

    public function make(string $driver): DatabaseDumper
    {
        $dumper = $this->dumpers[$driver] ?? throw new RuntimeException(
            "No backup dumper is registered for the [{$driver}] driver."
        );

        /** @var DatabaseDumper */
        return $this->container->make($dumper);
    }
}
