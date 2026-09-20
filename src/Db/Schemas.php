<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Db;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

final class Schemas
{
    private function __construct() {}

    /** @return list<string> the schemas this connection owns, public when it says nothing */
    public static function owned(?Connection $connection = null): array
    {
        $listing = ($connection ?? DB::connection())->getSchemaBuilder()->getCurrentSchemaListing();

        $schemas = is_array($listing) && $listing !== [] ? $listing : ['public'];

        return array_values(array_unique(array_map(Identifier::of(...), $schemas)));
    }

    /**
     * @return array{sql: string, bindings: list<string>}
     */
    public static function filter(string $column, ?Connection $connection = null): array
    {
        $schemas = self::owned($connection);

        return [
            'sql' => self::dotted($column).' in ('.implode(', ', array_fill(0, count($schemas), '?')).')',
            'bindings' => $schemas,
        ];
    }

    public static function ensure(Connection $connection): void
    {
        foreach (self::owned($connection) as $schema) {
            if ($schema !== 'public') {
                $connection->statement("create schema if not exists {$schema}");
            }
        }
    }

    /** @return list<array{signature: string, keyword: string}> */
    public static function routines(Connection $connection): array
    {
        $namespace = self::filter('n.nspname', $connection);

        $rows = $connection->select(
            'select p.oid::regprocedure::text as signature, p.prokind as kind '
            .'from pg_proc p join pg_namespace n on n.oid = p.pronamespace '
            .'where '.$namespace['sql']
            ."  and p.prokind in ('f', 'p') "
            ."  and not exists (select 1 from pg_depend d where d.objid = p.oid and d.deptype = 'e') "
            .'order by 1',
            $namespace['bindings'],
        );

        $routines = [];

        foreach ($rows as $row) {
            if (is_object($row) && is_scalar($row->signature ?? null)) {
                $routines[] = [
                    'signature' => (string) $row->signature,
                    'keyword' => ($row->kind ?? '') === 'p' ? 'procedure' : 'function',
                ];
            }
        }

        return $routines;
    }

    /**
     * @param  list<string>  $ignore
     * @return array<string, array<string, ColumnShape>>
     */
    public static function shape(Connection $connection, array $ignore = []): array
    {
        $columns = self::filter('c.table_schema', $connection);
        $tables = self::filter('t.schemaname', $connection);

        $rows = $connection->select(
            'select table_name, column_name, data_type, coalesce(character_maximum_length, -1) as len, '
            .'is_nullable, column_default from information_schema.columns c '
            .'where '.$columns['sql'].' and exists ('
            .'  select 1 from pg_tables t where '.$tables['sql'].' and t.tablename = c.table_name'
            .') order by table_name, column_name',
            [...$columns['bindings'], ...$tables['bindings']],
        );

        $shape = [];

        foreach ($rows as $row) {
            if (! is_object($row) || in_array($row->table_name ?? null, $ignore, true)) {
                continue;
            }

            $column = ColumnShape::fromRow($row);

            $shape[$column->table][$column->name] = $column;
        }

        return $shape;
    }

    /**
     * @param  array<string, array<string, ColumnShape>>  $live
     * @param  array<string, array<string, ColumnShape>>  $rebuilt
     * @param  callable(string, ?string): int  $held
     * @return array{blocking: list<string>, discarded: list<string>, discardedTables: list<string>, discardedColumns: list<array{table: string, column: string}>, carried: int, newTables: int, additions: int}
     */
    public static function drift(array $live, array $rebuilt, callable $held): array
    {
        $blocking = [];
        $discarded = [];
        $discardedTables = [];
        $discardedColumns = [];
        $additions = 0;

        foreach ($live as $table => $columns) {
            if (! array_key_exists($table, $rebuilt)) {
                $rows = $held($table, null);

                if ($rows === 0) {
                    $discarded[] = "table {$table}";
                    $discardedTables[] = $table;
                } else {
                    $blocking[] = "table {$table} still holds {$rows} rows and the new migrations do not define it";
                }

                continue;
            }

            $populated = $held($table, null) > 0;

            foreach ($columns as $column => $shape) {
                $target = $rebuilt[$table][$column] ?? null;

                if ($target === null) {
                    $values = $held($table, $column);

                    if ($values === 0) {
                        $discarded[] = "{$table}.{$column}";
                        $discardedColumns[] = ['table' => $table, 'column' => $column];
                    } else {
                        $blocking[] = "{$table}.{$column} still holds {$values} values and the new migrations do not define it";
                    }

                    continue;
                }

                if (! $populated) {
                    continue;
                }

                if ($target->dataType !== $shape->dataType) {
                    $blocking[] = "{$table}.{$column} changes from {$shape->dataType} to {$target->dataType}";

                    continue;
                }

                if ($target->length > 0 && $shape->length > $target->length) {
                    $blocking[] = "{$table}.{$column} narrows from {$shape->dataType}({$shape->length}) to ({$target->length})";
                }
            }

            foreach ($rebuilt[$table] as $column => $shape) {
                if (array_key_exists($column, $columns)) {
                    continue;
                }

                $additions++;

                if ($populated && ! $shape->nullable && $shape->default === null) {
                    $blocking[] = "{$table}.{$column} is new, not null and has no default, so the old rows cannot be reloaded";
                }
            }
        }

        return [
            'blocking' => $blocking,
            'discarded' => $discarded,
            'discardedTables' => $discardedTables,
            'discardedColumns' => $discardedColumns,
            'carried' => count($live),
            'newTables' => count(array_diff(array_keys($rebuilt), array_keys($live))),
            'additions' => $additions,
        ];
    }

    private static function dotted(string $column): string
    {
        foreach (explode('.', $column) as $part) {
            Identifier::of($part);
        }

        return $column;
    }
}
