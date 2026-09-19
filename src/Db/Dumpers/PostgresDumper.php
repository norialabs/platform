<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Db\Dumpers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use NoriaLabs\Platform\Contracts\DatabaseDumper;
use NoriaLabs\Platform\Contracts\DatabaseMaintainer;
use NoriaLabs\Platform\Db\Connections;
use NoriaLabs\Platform\Db\Identifier;
use RuntimeException;
use Throwable;

class PostgresDumper implements DatabaseDumper, DatabaseMaintainer
{
    public function driver(): string
    {
        return 'pgsql';
    }

    public function extension(): string
    {
        return 'sql.gz';
    }

    /** @param array<string, mixed> $connection */
    public function dump(array $connection, string $destination): void
    {
        $this->preflight($connection);

        $this->run($connection, [
            'pg_dump',
            ...$this->target($connection),
            '--no-owner',
            '--no-privileges',
            '--format=plain',
            '--compress='.max(0, min(9, Config::integer('noria.db.compression', 9))),
            '--file='.$destination,
        ], 'pg_dump');
    }

    /** @param array<string, mixed> $connection */
    public function restore(array $connection, string $source): void
    {
        $this->run($connection, [
            'psql',
            ...$this->target($connection),
            '--set=ON_ERROR_STOP=1',
            '--quiet',
            '--file='.$source,
        ], 'psql');
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    public function preflight(array $connection): void
    {
        $appRole = Connections::value($connection, 'username', 'the application role');

        $instruction = 'A backup needs a role that bypasses row level security: '
            ."create role <name> login password '...' nosuperuser nocreatedb nocreaterole bypassrls in role {$appRole}; "
            .'then point noria.db.admin_connection at it.';

        $probe = Connections::open('preflight', $connection);

        try {
            $role = $probe->selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = current_user');
        } catch (Throwable $e) {
            throw new RuntimeException('Could not connect as the backup role: '.$e->getMessage().' '.$instruction, previous: $e);
        } finally {
            $probe->disconnect();
        }

        if (! is_object($role) || (! ($role->rolsuper ?? false) && ! ($role->rolbypassrls ?? false))) {
            throw new RuntimeException(
                'The backup role cannot bypass row level security, so the dump would be empty or partial. '.$instruction
            );
        }
    }

    /** @param array<string, mixed> $connection */
    public function ensureDatabase(array $connection, string $database, string $appRole): bool
    {
        $name = Identifier::of($database);
        $owner = Connections::value($connection, 'username');

        $maintenance = Connections::open('maintenance', [...$connection, 'database' => 'postgres']);

        try {
            if ($maintenance->scalar('select 1 from pg_database where datname = ?', [$database]) !== null) {
                return false;
            }

            $role = $maintenance->selectOne('select rolsuper, rolcreatedb from pg_roles where rolname = current_user');

            if (! is_object($role) || (! ($role->rolsuper ?? false) && ! ($role->rolcreatedb ?? false))) {
                throw new RuntimeException(
                    "Role [{$owner}] cannot create a database, so [{$database}] has to exist already. "
                    ."Create it as a superuser, or run: alter role {$owner} createdb;"
                );
            }

            $grantees = array_values(array_unique(array_filter([
                Identifier::of($owner),
                $appRole === '' ? null : Identifier::of($appRole),
            ])));

            $maintenance->statement("create database {$name} owner ".Identifier::of($owner));
            $maintenance->statement("revoke all on database {$name} from public");
            $maintenance->statement("grant connect on database {$name} to ".implode(', ', $grantees));

            return true;
        } finally {
            $maintenance->disconnect();
        }
    }

    /** @param array<string, mixed> $connection */
    public function grantSchema(array $connection, string $appRole): void
    {
        $ownerName = Connections::value($connection, 'username');
        $owner = Identifier::of($ownerName);

        $admin = Connections::open('grants', $connection);

        try {
            $admin->statement('revoke all on schema public from public');
            $admin->statement("grant usage, create on schema public to {$owner}");

            if ($appRole === '' || $appRole === $ownerName) {
                return;
            }

            $app = Identifier::of($appRole);

            $admin->statement("grant usage on schema public to {$app}");
            $admin->statement("alter default privileges for role {$owner} in schema public grant select, insert, update, delete on tables to {$app}");
            $admin->statement("alter default privileges for role {$owner} in schema public grant usage, select on sequences to {$app}");
            $admin->statement("alter default privileges for role {$owner} in schema public grant execute on functions to {$app}");
        } finally {
            $admin->disconnect();
        }
    }

    /** @param array<string, mixed> $connection */
    public function dropExisting(array $connection): int
    {
        $admin = Connections::open('drop', $connection);

        try {
            $before = $admin->scalar('select count(*) from pg_tables where schemaname = current_schema()');

            $builder = $admin->getSchemaBuilder();
            $builder->dropAllViews();
            $builder->dropAllTables();
            $builder->dropAllTypes();

            return is_numeric($before) ? (int) $before : 0;
        } finally {
            $admin->disconnect();
        }
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return list<string>
     */
    private function target(array $connection): array
    {
        return [
            '--host='.Connections::value($connection, 'host', '127.0.0.1'),
            '--port='.Connections::value($connection, 'port', '5432'),
            '--username='.Connections::value($connection, 'username'),
            '--dbname='.Connections::value($connection, 'database'),
        ];
    }

    /**
     * @param  array<string, mixed>  $connection
     * @param  list<string>  $command
     */
    private function run(array $connection, array $command, string $tool): void
    {
        $password = Connections::value($connection, 'password');

        $result = Process::env($password === '' ? [] : ['PGPASSWORD' => $password])
            ->timeout(max(60, Config::integer('noria.db.timeout', 1800)))
            ->run($command);

        if ($result->failed()) {
            throw new RuntimeException($tool.' failed: '.trim($result->errorOutput() ?: $result->output()));
        }
    }
}
