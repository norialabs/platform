<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Db\Dumpers;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use NoriaLabs\Platform\Contracts\DatabaseDumper;
use NoriaLabs\Platform\Contracts\DatabaseMaintainer;
use NoriaLabs\Platform\Db\Connections;
use NoriaLabs\Platform\Db\Identifier;
use NoriaLabs\Platform\Db\Schemas;
use RuntimeException;
use Throwable;

class PostgresDumper implements DatabaseDumper, DatabaseMaintainer
{
    private const CREATE_EXTENSION = '/^\s*CREATE\s+EXTENSION\s+(?:IF\s+NOT\s+EXISTS\s+)?"?([A-Za-z0-9_-]+)"?/i';

    private const EXTENSION_COMMENT = '/^\s*COMMENT\s+ON\s+EXTENSION\s+.*;\s*$/i';

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
        $readable = $this->withoutExtensionComments($source);

        try {
            $this->run($connection, [
                'psql',
                ...$this->target($connection),
                '--single-transaction',
                '--set=ON_ERROR_STOP=1',
                '--quiet',
                '--file='.$readable,
            ], 'psql');
        } finally {
            if ($readable !== $source && is_file($readable)) {
                @unlink($readable);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return list<string>
     */
    public function ensureExtensions(array $connection, string $source): array
    {
        $named = $this->extensionsNamedIn($source);

        if ($named === []) {
            return [];
        }

        $target = Connections::open('extensions', $connection);

        try {
            $missing = array_values(array_diff($named, $this->extensionsIn($target)));

            if ($missing === []) {
                return [];
            }

            $installed = $this->install($target, $missing);
        } finally {
            $target->disconnect();
        }

        $refused = array_values(array_diff($missing, $installed));

        return $refused === []
            ? $installed
            : [...$installed, ...$this->installAsSuperuser($connection, $refused)];
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

            foreach (Schemas::routines($admin) as $routine) {
                $admin->statement("drop {$routine['keyword']} if exists {$routine['signature']} cascade");
            }

            return is_numeric($before) ? (int) $before : 0;
        } finally {
            $admin->disconnect();
        }
    }

    /** @return list<string> */
    private function extensionsNamedIn(string $source): array
    {
        $handle = @fopen($source, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to read the dump at {$source}.");
        }

        $named = [];

        try {
            while (($line = fgets($handle)) !== false) {
                if (preg_match(self::CREATE_EXTENSION, $line, $matches) === 1 && $matches[1] !== 'plpgsql') {
                    $named[$matches[1]] = true;
                }
            }
        } finally {
            fclose($handle);
        }

        return array_keys($named);
    }

    /** @return list<string> */
    private function extensionsIn(Connection $connection): array
    {
        $found = [];

        foreach ($connection->select('select extname from pg_extension') as $row) {
            if (is_object($row) && is_scalar($row->extname ?? null)) {
                $found[] = (string) $row->extname;
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $extensions
     * @return list<string>
     */
    private function install(Connection $connection, array $extensions): array
    {
        $installed = [];

        foreach ($extensions as $extension) {
            try {
                $connection->statement('create extension if not exists '.Identifier::quoted($extension));
                $installed[] = $extension;
            } catch (QueryException) {
                continue;
            }
        }

        return $installed;
    }

    /**
     * @param  array<string, mixed>  $connection
     * @param  list<string>  $extensions
     * @return list<string>
     */
    private function installAsSuperuser(array $connection, array $extensions): array
    {
        $name = Config::get('noria.db.superuser_connection');
        $database = Connections::value($connection, 'database');

        if (! is_string($name) || $name === '') {
            throw new RuntimeException($this->byHand($database, $extensions));
        }

        $elevated = Connections::open('superuser', [
            ...Connections::settings($name),
            'database' => $database,
        ]);

        try {
            $installed = $this->install($elevated, $extensions);
        } finally {
            $elevated->disconnect();
        }

        $refused = array_values(array_diff($extensions, $installed));

        if ($refused !== []) {
            throw new RuntimeException($this->byHand($database, $refused));
        }

        return $installed;
    }

    /** @param list<string> $extensions */
    private function byHand(string $database, array $extensions): string
    {
        $statements = implode(' ', array_map(
            fn (string $name): string => 'create extension if not exists '.Identifier::quoted($name).';',
            $extensions,
        ));

        return 'The dump names extensions ['.implode(', ', $extensions)."] that [{$database}] does not have "
            .'and this role may not install. Point noria.db.superuser_connection at a role that may, '
            ."or run this against {$database} as one: {$statements}";
    }

    private function withoutExtensionComments(string $source): string
    {
        if (! $this->carriesExtensionComments($source)) {
            return $source;
        }

        $destination = $source.'.readable';

        $in = @fopen($source, 'rb');
        $out = @fopen($destination, 'wb');

        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }

            if (is_resource($out)) {
                fclose($out);
            }

            throw new RuntimeException("Unable to rewrite the dump at {$source}.");
        }

        try {
            while (($line = fgets($in)) !== false) {
                if (preg_match(self::EXTENSION_COMMENT, $line) !== 1) {
                    fwrite($out, $line);
                }
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return $destination;
    }

    private function carriesExtensionComments(string $source): bool
    {
        $handle = @fopen($source, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to read the dump at {$source}.");
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if (preg_match(self::EXTENSION_COMMENT, $line) === 1) {
                    return true;
                }
            }
        } finally {
            fclose($handle);
        }

        return false;
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
