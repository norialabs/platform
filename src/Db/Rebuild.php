<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Db;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class Rebuild
{
    /**
     * @param  callable(string): void  $report
     * @param  list<string>  $bringUp  commands that bring reloaded rows up to what this release reads, run while the grandfathering checks are still held back
     * @return array{copy: string, dump: string|null, tables: int, rows: int}
     */
    public function run(string $copy, bool $keep, callable $report, array $bringUp = []): array
    {
        $settings = Connections::settings();
        $database = Connections::value($settings, 'database');
        $maintenance = Connections::asAdmin($settings);
        $admin = $this->connectTo($maintenance, 'postgres');

        $this->assertPostgres($settings);
        $this->assertPrivileges($admin, Connections::value($maintenance, 'username'), $database);
        $this->assertToolsMatchServer($admin);
        $this->assertAbsent($admin, $copy);

        $shape = Schemas::shape($this->connectTo($maintenance, $database), $this->unrestored());
        $this->release($database);

        $drift = $this->assertNewSchemaFits($admin, $settings, $maintenance, $shape, $report);

        $this->assertSoleSession($admin, $database, $report);
        $report("Copying {$database} to {$copy}.");
        $this->createDatabase($admin, $copy, $database, Connections::value($maintenance, 'username'));

        $dump = null;
        $expected = [];

        try {
            $carried = array_values(array_diff(array_keys($shape), $drift['discardedTables']));
            $copyConnection = $this->connectTo($maintenance, $copy);
            $this->alignToRebuiltShape($copyConnection, $drift, $report);
            $expected = $this->rowCounts($copyConnection, $carried);
            $this->release($copy);
            $report(sprintf('Copied %d tables holding %d rows.', count($expected), array_sum($expected)));

            $dump = $this->dump($maintenance, $copy, $report);

            $report('Rebuilding the schema from the current migrations.');
            $this->dropOrphanedRoutines($this->connectTo($maintenance, $database), $report);
            Schemas::ensure($this->connectTo($maintenance, $database));
            $this->release($database);
            $this->migrateFresh($maintenance);

            $rebuilt = $this->connectTo($maintenance, $database);
            $this->assertAppCanUse(
                $rebuilt,
                Connections::value($settings, 'username'),
                Connections::value($maintenance, 'username'),
            );
            $deferred = $this->unvalidatedChecks($rebuilt);

            if ($deferred !== []) {
                $report(sprintf(
                    'Holding back %d constraint(s) that grandfather existing rows: %s.',
                    count($deferred),
                    implode(', ', array_column($deferred, 'name')),
                ));
                $this->dropConstraints($rebuilt, $deferred);
            }

            $this->restore($maintenance, $rebuilt, $database, $dump, $report);
            $this->verifyRowCounts($this->connectTo($maintenance, $database), $expected, $report);
            $this->bringUp($bringUp, $report);
            $this->addChecks($this->connectTo($maintenance, $database), $deferred);
            $this->verify($report);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                $exception->getMessage()."\n\n"
                ."{$database} may be incomplete. Nothing was deleted: restore it with\n"
                ."  alter database {$database} rename to {$database}_failed;\n"
                ."  alter database {$copy} rename to {$database};",
                previous: $exception,
            );
        }

        if (! $keep) {
            $report("Dropping {$copy} and the dump.");
            $this->dropDatabase($admin, $copy);
            $this->forget($dump);
            $dump = null;
        }

        return ['copy' => $copy, 'dump' => $keep ? $dump : null, 'tables' => count($expected), 'rows' => array_sum($expected)];
    }

    /**
     * @param  array{discardedTables: list<string>, discardedColumns: list<array{table: string, column: string}>}  $drift
     * @param  callable(string): void  $report
     */
    public function alignToRebuiltShape(Connection $copy, array $drift, callable $report): void
    {
        if ($drift['discardedTables'] === [] && $drift['discardedColumns'] === []) {
            return;
        }

        foreach ($drift['discardedTables'] as $table) {
            $copy->statement('drop table if exists "'.Identifier::of($table).'" cascade');
        }

        foreach ($drift['discardedColumns'] as ['table' => $table, 'column' => $column]) {
            $copy->statement(
                'alter table if exists "'.Identifier::of($table).'" drop column if exists "'.Identifier::of($column).'" cascade'
            );
        }

        $report(sprintf(
            'Trimmed the copy to the rebuilt shape: %d table(s), %d column(s).',
            count($drift['discardedTables']),
            count($drift['discardedColumns']),
        ));
    }

    /**
     * @param  callable(string): void  $report
     * @return list<string>
     */
    public function dropOrphanedRoutines(Connection $connection, callable $report): array
    {
        $namespace = Schemas::filter('n.nspname', $connection);

        $routines = $connection->select(
            'select p.oid::regprocedure::text as signature, p.prokind as kind '
            .'from pg_proc p join pg_namespace n on n.oid = p.pronamespace '
            .'where '.$namespace['sql']
            ."  and p.prokind in ('f', 'p') "
            ."  and not exists (select 1 from pg_depend d where d.objid = p.oid and d.deptype = 'e') "
            .'order by 1',
            $namespace['bindings'],
        );

        $dropped = [];

        foreach ($routines as $routine) {
            if (! is_object($routine) || ! is_scalar($routine->signature ?? null)) {
                continue;
            }

            $signature = (string) $routine->signature;
            $keyword = ($routine->kind ?? '') === 'p' ? 'procedure' : 'function';

            $connection->statement("drop {$keyword} if exists {$signature} cascade");
            $dropped[] = $signature;
        }

        if ($dropped !== []) {
            $report(sprintf('Dropped %d routine(s) for the migrations to recreate.', count($dropped)));
        }

        return $dropped;
    }

    /** @param array<string, mixed> $settings */
    private function assertPostgres(array $settings): void
    {
        $driver = Connections::driver($settings);

        if ($driver !== 'pgsql') {
            throw new RuntimeException("A rebuild needs a pgsql connection; this one uses [{$driver}].");
        }
    }

    private function assertPrivileges(Connection $admin, string $role, string $database): void
    {
        $found = $admin->selectOne(
            'select rolsuper or rolbypassrls as bypasses, rolsuper or rolcreatedb as creates, '
            ."pg_has_role(current_user, 'pg_signal_backend', 'usage') as signals, "
            .'(select pg_has_role(current_user, d.datdba, \'usage\') from pg_database d where d.datname = ?) as owns '
            .'from pg_roles where rolname = current_user',
            [$database],
        );

        $missing = array_values(array_filter([
            is_object($found) && ($found->bypasses ?? false) ? null
                : "BYPASSRLS, or the dump comes back empty for every tenant table (alter role {$role} bypassrls)",
            is_object($found) && ($found->creates ?? false) ? null
                : "CREATEDB, or the safety copy cannot be taken (alter role {$role} createdb)",
            is_object($found) && ($found->signals ?? false) ? null
                : "membership of pg_signal_backend, or sessions left on {$database} cannot be cleared "
                  ."(grant pg_signal_backend to {$role})",
            is_object($found) && ($found->owns ?? false) ? null
                : "ownership of {$database}, or it cannot be copied or renamed (alter database {$database} owner to {$role})",
        ]));

        if ($missing !== []) {
            throw new RuntimeException(
                "The rebuild role [{$role}] cannot carry the reload through. It needs:\n  - ".implode("\n  - ", $missing)
            );
        }
    }

    private function assertAppCanUse(Connection $rebuilt, string $app, string $owner): void
    {
        if ($app === '' || $app === $owner) {
            return;
        }

        $namespace = Schemas::filter('n.nspname', $rebuilt);

        $rows = $rebuilt->select(
            'select c.oid::regclass::text as name from pg_class c join pg_namespace n on n.oid = c.relnamespace '
            ."where c.relkind in ('r', 'S') and ".$namespace['sql'].' and not case c.relkind '
            ."when 'r' then has_table_privilege(?, c.oid, 'select') and has_table_privilege(?, c.oid, 'insert') "
            ."and has_table_privilege(?, c.oid, 'update') and has_table_privilege(?, c.oid, 'delete') "
            ."else has_sequence_privilege(?, c.oid, 'usage') and has_sequence_privilege(?, c.oid, 'select') end "
            .'order by 1',
            [...$namespace['bindings'], $app, $app, $app, $app, $app, $app],
        );

        $blind = [];

        foreach ($rows as $row) {
            if (is_object($row)) {
                $blind[] = self::text($row->name ?? null);
            }
        }

        if ($blind === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'The rebuilt schema belongs to [%s], and the app role [%s] cannot use %d of its objects, including %s. '
            .'Grant the default privileges for [%s] to [%s] on the cluster, then rebuild.',
            $owner,
            $app,
            count($blind),
            implode(', ', array_slice($blind, 0, 3)),
            $owner,
            $app,
        ));
    }

    private function assertToolsMatchServer(Connection $admin): void
    {
        $server = self::number($admin->scalar("select current_setting('server_version_num')::int / 10000"));

        foreach (['pg_dump', 'pg_restore'] as $tool) {
            $result = Process::run([$tool, '--version']);

            if ($result->failed()) {
                throw new RuntimeException("{$tool} is not on PATH; run this from an image that carries the client.");
            }

            preg_match('/(\d+)/', $result->output(), $matches);
            $client = (int) ($matches[1] ?? 0);

            if ($client < $server) {
                throw new RuntimeException(
                    "{$tool} is version {$client} but the server is {$server}; it would refuse to read this database."
                );
            }
        }
    }

    private function assertAbsent(Connection $admin, string $name): void
    {
        if ($admin->selectOne('select 1 from pg_database where datname = ?', [$name]) !== null) {
            throw new RuntimeException("Database [{$name}] already exists. Drop it or pass another copy name.");
        }
    }

    /** @param callable(string): void $report */
    private function assertSoleSession(Connection $admin, string $database, callable $report): void
    {
        DB::purge();

        $others = self::number($admin->scalar(
            'select count(*) from pg_stat_activity where datname = ? and pid <> pg_backend_pid()',
            [$database],
        ));

        if ($others > 0) {
            throw new RuntimeException(
                "{$others} other connections are open to {$database}; the copy cannot be taken. "
                .'Stop the application and worker containers first.'
            );
        }

        $report("No other sessions hold {$database}.");
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $maintenance
     * @param  array<string, array<string, ColumnShape>>  $shape
     * @param  callable(string): void  $report
     * @return array{discardedTables: list<string>, discardedColumns: list<array{table: string, column: string}>}
     */
    private function assertNewSchemaFits(
        Connection $admin,
        array $settings,
        array $maintenance,
        array $shape,
        callable $report,
    ): array {
        $database = Connections::value($settings, 'database');
        $probe = $database.'_rebuild_probe';

        $this->dropDatabase($admin, $probe);
        $this->createDatabase($admin, $probe, null, Connections::value($maintenance, 'username'));

        $default = Config::string('database.default');

        try {
            Config::set('database.connections.noria-rebuild-probe', [...$maintenance, 'database' => $probe]);
            DB::purge('noria-rebuild-probe');

            if (Artisan::call('migrate', ['--database' => 'noria-rebuild-probe', '--force' => true]) !== 0) {
                throw new RuntimeException(
                    'The current migrations do not run against an empty database: '.trim(Artisan::output())
                );
            }

            $rebuilt = Schemas::shape($this->connectTo($maintenance, $probe), $this->unrestored());
            $live = $this->connectTo($maintenance, $database);

            try {
                return $this->reportDrift($shape, $rebuilt, $live, $report);
            } finally {
                $this->release($database);
            }
        } finally {
            DB::setDefaultConnection($default);
            $this->release($probe);
            DB::purge('noria-rebuild-probe');
            $this->dropDatabase($admin, $probe);
        }
    }

    /**
     * @param  array<string, array<string, ColumnShape>>  $live
     * @param  array<string, array<string, ColumnShape>>  $rebuilt
     * @param  callable(string): void  $report
     * @return array{discardedTables: list<string>, discardedColumns: list<array{table: string, column: string}>}
     */
    private function reportDrift(array $live, array $rebuilt, Connection $connection, callable $report): array
    {
        $drift = Schemas::drift(
            $live,
            $rebuilt,
            fn (string $table, ?string $column): int => $this->held($connection, $table, $column),
        );

        $report(sprintf(
            'Schema check: %d tables carried over, %d new tables, %d new columns.',
            $drift['carried'],
            $drift['newTables'],
            $drift['additions'],
        ));

        if ($drift['discarded'] !== []) {
            $report('Dropping, empty and no longer defined: '.implode(', ', $drift['discarded']).'.');
        }

        if ($drift['blocking'] !== []) {
            throw new RuntimeException(
                "The new migrations cannot hold the existing data:\n  - ".implode("\n  - ", $drift['blocking'])
                ."\nNothing has been changed. Add a migration that moves this data, or keep the columns."
            );
        }

        return [
            'discardedTables' => $drift['discardedTables'],
            'discardedColumns' => $drift['discardedColumns'],
        ];
    }

    private function held(Connection $connection, string $table, ?string $column = null): int
    {
        $where = $column === null ? '' : ' where "'.Identifier::of($column).'" is not null';

        return self::number($connection->scalar('select count(*) from "'.Identifier::of($table).'"'.$where));
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    private function rowCounts(Connection $connection, array $tables): array
    {
        if ($tables === []) {
            return [];
        }

        $query = implode(' union all ', array_map(
            fn (string $table): string => 'select '.$connection->getPdo()->quote($table)
                .' as name, count(*) as rows from "'.Identifier::of($table).'"',
            $tables,
        ));

        $counts = [];

        foreach ($connection->select($query) as $row) {
            if (is_object($row) && is_scalar($row->name ?? null)) {
                $counts[(string) $row->name] = self::number($row->rows ?? null);
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $maintenance
     * @param  callable(string): void  $report
     */
    private function dump(array $maintenance, string $source, callable $report): string
    {
        $path = $this->workingPath($source);

        $arguments = [
            'pg_dump',
            ...$this->target($maintenance, $source),
            '--data-only',
            '--format=custom',
            '--no-owner',
            '--no-privileges',
            '--file='.$path,
        ];

        foreach ($this->unrestored() as $table) {
            $arguments[] = '--exclude-table-data=*.'.$table;
        }

        $this->execute($maintenance, $arguments, 'pg_dump');

        $bytes = (int) @filesize($path);

        if ($bytes === 0) {
            throw new RuntimeException('pg_dump produced an empty file.');
        }

        $report(sprintf('Dumped the data to %s (%.1f MB).', $path, $bytes / 1_048_576));

        return $path;
    }

    /**
     * Loads rows in whatever order they arrive by holding the foreign keys and user triggers off,
     * then putting them back, which validates every reference the load has just made.
     *
     * @param  callable(): void  $load
     * @return int the number of foreign keys put back
     */
    public function holdOffReferences(Connection $rebuilt, callable $load): int
    {
        $keys = $this->foreignKeys($rebuilt);

        $this->dropConstraints($rebuilt, $keys);
        $this->setUserTriggers($rebuilt, enabled: false);

        $load();

        $this->setUserTriggers($rebuilt, enabled: true);
        $this->addForeignKeys($rebuilt, $keys);

        return count($keys);
    }

    /** @return list<array{table: string, name: string, definition: string}> */
    private function unvalidatedChecks(Connection $connection): array
    {
        return $this->constraints($connection, "c.contype = 'c' and not c.convalidated");
    }

    /** @return list<array{table: string, name: string, definition: string}> */
    private function foreignKeys(Connection $connection): array
    {
        return $this->constraints($connection, "c.contype = 'f'");
    }

    /** @return list<array{table: string, name: string, definition: string}> */
    private function constraints(Connection $connection, string $held): array
    {
        $namespace = Schemas::filter('n.nspname', $connection);

        $rows = $connection->select(
            'select conrelid::regclass::text as tbl, conname as name, pg_get_constraintdef(c.oid) as definition '
            .'from pg_constraint c join pg_namespace n on n.oid = c.connamespace '
            .'where '.$held.' and '.$namespace['sql'].' order by conname',
            $namespace['bindings'],
        );

        $constraints = [];

        foreach ($rows as $row) {
            if (is_object($row)) {
                $constraints[] = [
                    'table' => self::text($row->tbl ?? null),
                    'name' => self::text($row->name ?? null),
                    'definition' => self::text($row->definition ?? null),
                ];
            }
        }

        return $constraints;
    }

    /** @param list<array{table: string, name: string, definition: string}> $constraints */
    private function dropConstraints(Connection $connection, array $constraints): void
    {
        foreach ($constraints as $constraint) {
            $connection->statement('alter table '.$constraint['table'].' drop constraint "'.$constraint['name'].'"');
        }
    }

    /** @param list<array{table: string, name: string, definition: string}> $keys */
    private function addForeignKeys(Connection $connection, array $keys): void
    {
        foreach ($keys as $key) {
            $connection->statement('alter table '.$key['table'].' add constraint "'.$key['name'].'" '.$key['definition']);
        }
    }

    private function setUserTriggers(Connection $connection, bool $enabled): void
    {
        $namespace = Schemas::filter('n.nspname', $connection);

        $rows = $connection->select(
            'select c.oid::regclass::text as tbl from pg_class c join pg_namespace n on n.oid = c.relnamespace '
            ."where c.relkind = 'r' and c.relhastriggers and ".$namespace['sql'].' order by 1',
            $namespace['bindings'],
        );

        foreach ($rows as $row) {
            if (is_object($row)) {
                $connection->statement(
                    'alter table '.self::text($row->tbl ?? null).' '.($enabled ? 'enable' : 'disable').' trigger user'
                );
            }
        }
    }

    /** @param list<array{table: string, name: string, definition: string}> $checks */
    private function addChecks(Connection $connection, array $checks): void
    {
        foreach ($checks as $check) {
            $definition = str_ends_with($check['definition'], 'NOT VALID')
                ? $check['definition']
                : $check['definition'].' NOT VALID';

            $connection->statement('alter table '.$check['table'].' add constraint "'.$check['name'].'" '.$definition);
        }
    }

    /** @param array<string, mixed> $maintenance */
    private function migrateFresh(array $maintenance): void
    {
        DB::purge();

        Config::set('database.connections.noria-rebuild-fresh', $maintenance);
        DB::purge('noria-rebuild-fresh');

        try {
            if (Artisan::call('migrate:fresh', ['--database' => 'noria-rebuild-fresh', '--force' => true]) !== 0) {
                throw new RuntimeException('migrate:fresh failed: '.trim(Artisan::output()));
            }
        } finally {
            DB::purge('noria-rebuild-fresh');
        }
    }

    /**
     * @param  array<string, mixed>  $maintenance
     * @param  callable(string): void  $report
     */
    private function restore(array $maintenance, Connection $rebuilt, string $database, string $dump, callable $report): void
    {
        $keys = $this->holdOffReferences($rebuilt, fn () => $this->execute($maintenance, [
            'pg_restore',
            ...$this->target($maintenance, $database),
            '--data-only',
            '--single-transaction',
            '--no-owner',
            '--no-privileges',
            $dump,
        ], 'pg_restore'));

        $report(sprintf('Reloaded the data and validated %d foreign key(s) against it.', $keys));
    }

    /**
     * @param  list<string>  $commands
     * @param  callable(string): void  $report
     */
    private function bringUp(array $commands, callable $report): void
    {
        foreach ($commands as $command) {
            $report("Bringing the reloaded rows up to {$command}.");

            if (Artisan::call($command, ['--no-interaction' => true]) !== 0) {
                throw new RuntimeException("{$command} failed on the reloaded rows:\n".trim(Artisan::output()));
            }
        }
    }

    /**
     * @param  array<string, int>  $expected
     * @param  callable(string): void  $report
     */
    private function verifyRowCounts(Connection $connection, array $expected, callable $report): void
    {
        $actual = $this->rowCounts($connection, array_keys($expected));
        $short = [];

        foreach ($expected as $table => $rows) {
            $found = $actual[$table] ?? 0;

            if ($found !== $rows) {
                $short[] = "{$table}: expected {$rows} rows, found {$found}";
            }
        }

        if ($short !== []) {
            throw new RuntimeException("The reload did not put every row back:\n  - ".implode("\n  - ", $short));
        }

        $report(sprintf('Verified %d tables against the copy, every row accounted for.', count($expected)));
    }

    /**
     * @param  callable(string): void  $report
     */
    private function verify(callable $report): void
    {
        $commands = Config::array('noria.db.rebuild.verify_commands', []);

        foreach ($commands as $command) {
            if (! is_string($command)) {
                continue;
            }

            if (Artisan::call($command) !== 0) {
                throw new RuntimeException("{$command} failed on the rebuilt database: ".trim(Artisan::output()));
            }
        }

        if ($commands !== []) {
            $report('Integrity and constraint checks passed.');
        }
    }

    private function createDatabase(Connection $admin, string $name, ?string $template, string $owner): void
    {
        $sql = 'create database "'.Identifier::of($name).'"';

        if ($owner !== '') {
            $sql .= ' owner "'.Identifier::of($owner).'"';
        }

        if ($template !== null) {
            $sql .= ' template "'.Identifier::of($template).'"';
        }

        $admin->statement($sql);
    }

    private function dropDatabase(Connection $admin, string $name): void
    {
        $admin->statement('drop database if exists "'.Identifier::of($name).'" with (force)');
    }

    private function forget(?string $path): void
    {
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @param  array<string, mixed>  $maintenance
     * @param  list<string>  $arguments
     */
    private function execute(array $maintenance, array $arguments, string $tool): void
    {
        $password = Connections::value($maintenance, 'password');

        $result = Process::env($password === '' ? [] : ['PGPASSWORD' => $password])
            ->timeout(max(60, Config::integer('noria.db.timeout', 1800)))
            ->run($arguments);

        if ($result->failed()) {
            throw new RuntimeException("{$tool} failed: ".trim($result->errorOutput() ?: $result->output()));
        }
    }

    /**
     * @param  array<string, mixed>  $maintenance
     * @return list<string>
     */
    private function target(array $maintenance, string $database): array
    {
        return [
            '--host='.Connections::value($maintenance, 'host', '127.0.0.1'),
            '--port='.Connections::value($maintenance, 'port', '5432'),
            '--username='.Connections::value($maintenance, 'username'),
            '--dbname='.$database,
        ];
    }

    private function workingPath(string $database): string
    {
        $configured = Config::get('noria.db.working_directory');
        $directory = is_string($configured) && $configured !== ''
            ? $configured
            : sys_get_temp_dir().'/noria-backup';

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create the working directory at {$directory}.");
        }

        return $directory.'/'.Carbon::now('UTC')->format('Ymd-His').'-'.$database.'-rebuild.dump';
    }

    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** @return list<string> tables whose rows the rebuild does not carry over */
    private function unrestored(): array
    {
        $tables = Config::array('noria.db.rebuild.unrestored', ['migrations']);

        return array_values(array_filter($tables, is_string(...)));
    }

    /** @param array<string, mixed> $maintenance */
    private function connectTo(array $maintenance, string $database): Connection
    {
        /** @var Connection */
        return DB::connectUsing('noria-rebuild-'.$database, [...$maintenance, 'database' => $database], force: true);
    }

    private function release(string $database): void
    {
        DB::purge('noria-rebuild-'.$database);
    }
}
