<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use NoriaLabs\Platform\Platform;

class Invariants
{
    /** @return list<string> every way the connected role is more powerful than it should be */
    public static function roleFailures(): array
    {
        $role = self::connection()->selectOne(
            'select current_user as name, rolsuper, rolbypassrls from pg_roles where rolname = current_user'
        );

        if (! is_object($role)) {
            return ['the connected role could not be read from pg_roles'];
        }

        $name = is_scalar($role->name ?? null) ? (string) $role->name : 'the connected role';

        return array_values(array_filter([
            ($role->rolsuper ?? false) ? "{$name} is a superuser, so row level security does not apply to it" : null,
            ($role->rolbypassrls ?? false) ? "{$name} holds BYPASSRLS, so every policy is advisory" : null,
        ]));
    }

    /** @return list<string> tenant tables with no isolation policy */
    public static function tablesWithoutPolicy(): array
    {
        $rows = self::connection()->select(<<<'SQL'
            select c.relname as table_name
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            join pg_attribute a on a.attrelid = c.oid and a.attname = ? and a.attnum > 0
            where n.nspname = 'public'
              and c.relkind = 'r'
              and not exists (
                  select 1 from pg_policies p
                  where p.tablename = c.relname and p.policyname like '%tenant_isolation'
              )
            order by 1
        SQL, [Config::string('noria.tenancy.column', 'workspace_id')]);

        return array_values(array_diff(self::names($rows), self::unscoped()));
    }

    /** @return list<string> tables where the owner is still exempt from its own policies */
    public static function tablesWithUnforcedPolicy(): array
    {
        $rows = self::connection()->select(<<<'SQL'
            select c.relname as table_name
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public' and c.relkind = 'r' and c.relrowsecurity and not c.relforcerowsecurity
            order by 1
        SQL);

        return self::names($rows);
    }

    /** @return array<string, list<string>> every failure, grouped, empty when the deployment is sound */
    public static function all(): array
    {
        return array_filter([
            'role' => self::roleFailures(),
            'tables_without_policy' => self::tablesWithoutPolicy(),
            'tables_with_unforced_policy' => self::tablesWithUnforcedPolicy(),
        ], fn (array $failures): bool => $failures !== []);
    }

    /**
     * @param  array<mixed>  $rows
     * @return list<string>
     */
    private static function names(array $rows): array
    {
        $names = [];

        foreach ($rows as $row) {
            if (is_object($row) && is_scalar($row->table_name ?? null)) {
                $names[] = (string) $row->table_name;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function unscoped(): array
    {
        $tables = Config::array('noria.tenancy.unscoped_tables', []);

        return [
            ...array_values(array_filter($tables, is_string(...))),
            Platform::table('audit_logs'),
            Platform::table('otp_challenges'),
            Platform::table('invitations'),
        ];
    }

    private static function connection(): Connection
    {
        return DB::connection(Platform::connection());
    }
}
