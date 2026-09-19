<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use NoriaLabs\Platform\Platform;
use PDOException;
use RuntimeException;

class Rls
{
    public static function protect(string $table, bool $staffRead = false): void
    {
        self::enable($table);

        $predicate = self::tenantPredicate();

        self::run(
            'create policy '.self::policy($table, 'tenant_isolation')." on {$table} ".
            "using ({$predicate}) with check ({$predicate})"
        );

        if ($staffRead) {
            self::allowStaffRead($table);
        }
    }

    public static function protectAllowingGlobal(string $table, bool $staffRead = false): void
    {
        self::enable($table);

        $predicate = self::tenantPredicate();
        $column = self::column();

        self::run(
            'create policy '.self::policy($table, 'tenant_isolation')." on {$table} ".
            "using ({$column} is null or {$predicate}) with check ({$predicate})"
        );

        if ($staffRead) {
            self::allowStaffRead($table);
        }
    }

    public static function protectTrail(string $table): void
    {
        self::enable($table);

        $predicate = self::tenantPredicate();
        $column = self::column();

        self::run(
            'create policy '.self::policy($table, 'tenant_read')." on {$table} for select using ({$predicate})"
        );

        self::run(
            'create policy '.self::policy($table, 'append')." on {$table} for insert ".
            "with check ({$column} is null or {$predicate})"
        );

        self::allowStaffRead($table);
    }

    public static function allowStaffRead(string $table): void
    {
        $guc = Config::string('noria.tenancy.staff_read_guc', 'app.staff_read');

        self::run(
            'create policy '.self::policy($table, 'staff_read')." on {$table} for select ".
            "using (current_setting('{$guc}', true) = 'on')"
        );
    }

    public static function allowPlatformWrite(string $table): void
    {
        $guc = Config::string('noria.tenancy.noria_write_guc', 'app.noria_write');
        $test = "current_setting('{$guc}', true) = 'on'";

        self::run(
            'create policy '.self::policy($table, 'noria_write')." on {$table} for all ".
            "using ({$test}) with check ({$test})"
        );
    }

    public static function allowLookupByGuc(string $table, string $column, string $guc, ?string $cast = null): void
    {
        $value = "nullif(current_setting('{$guc}', true), '')".($cast === null ? '' : "::{$cast}");

        self::run(
            'create policy '.self::policy($table, $column.'_lookup')." on {$table} for select ".
            "using ({$column} = {$value})"
        );
    }

    public static function allowTrailProcessing(string $table): void
    {
        $test = '('.self::column().' is null or '.self::tenantPredicate().')';

        self::run(
            'create policy '.self::policy($table, 'processing')." on {$table} for update ".
            "using {$test} with check {$test}"
        );
    }

    public static function appendOnly(string $table): void
    {
        self::run(
            'create trigger '.self::policy($table, 'append_only')." before update or delete on {$table} ".
            'for each row execute function reject_mutation()'
        );
    }

    public static function defineRejectMutation(): void
    {
        self::connection()->unprepared(<<<'SQL'
            create or replace function reject_mutation() returns trigger as $$
            begin
                raise exception 'Table % is append-only', tg_table_name using errcode = 'restrict_violation';
            end;
            $$ language plpgsql;
        SQL);
    }

    public static function assertEnforced(): void
    {
        $failures = Invariants::roleFailures();

        if ($failures !== []) {
            throw new RuntimeException(
                'Tenant isolation is not enforceable: '.implode('; ', $failures).
                '. Connect as a constrained (NOSUPERUSER NOBYPASSRLS) role.'
            );
        }
    }

    public static function assertEnforcedIfReachable(): void
    {
        try {
            self::assertEnforced();
        } catch (QueryException $e) {
            if (! self::isConnectionFailure($e)) {
                throw $e;
            }
        }
    }

    private static function enable(string $table): void
    {
        self::run("alter table {$table} enable row level security");
        self::run("alter table {$table} force row level security");
    }

    private static function tenantPredicate(): string
    {
        $guc = Config::string('noria.tenancy.workspace_guc', 'app.workspace_id');

        return self::column()." = nullif(current_setting('{$guc}', true), '')::uuid";
    }

    private static function column(): string
    {
        return Config::string('noria.tenancy.column', 'workspace_id');
    }

    private static function policy(string $table, string $suffix): string
    {
        $bare = str_contains($table, '.')
            ? substr($table, (int) strrpos($table, '.') + 1)
            : $table;

        return $bare.'_'.$suffix;
    }

    private static function run(string $statement): void
    {
        self::connection()->statement($statement);
    }

    private static function connection(): Connection
    {
        return DB::connection(Platform::connection());
    }

    private static function isConnectionFailure(QueryException $e): bool
    {
        $state = self::sqlStateOf($e);

        return str_starts_with($state, '08') || $state === '3D000' || $state === 'HY000';
    }

    private static function sqlStateOf(QueryException $e): string
    {
        $previous = $e->getPrevious();

        if ($previous instanceof PDOException && is_string($previous->errorInfo[0] ?? null)) {
            return $previous->errorInfo[0];
        }

        return (string) $e->getCode();
    }
}
