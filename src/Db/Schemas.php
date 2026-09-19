<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Db;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * What this database looks like, and what changes between two shapes of it.
 */
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
     * A predicate restricting a catalog query to those schemas, so a rebuild
     * never reads a table that belongs to an extension.
     *
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

    /** Recreates the non-public schemas a fresh database would otherwise lack. */
    public static function ensure(Connection $connection): void
    {
        foreach (self::owned($connection) as $schema) {
            if ($schema !== 'public') {
                $connection->statement("create schema if not exists {$schema}");
            }
        }
    }

    /**
     * Every column of every table, keyed table then column.
     *
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
     * What moving from the live shape to the rebuilt one would cost.
     *
     * A table or column the migrations no longer define is discarded when it
     * is empty and blocking when it is not: the point of the exercise is
     * that no row is lost quietly. $held answers how many rows a table has,
     * or how many non-null values a column has.
     *
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

                // An empty table can change shape however it likes.
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
