<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Db;

final readonly class ColumnShape
{
    public function __construct(
        public string $table,
        public string $name,
        public string $dataType,
        public int $length,
        public bool $nullable,
        public ?string $default,
    ) {}

    public static function fromRow(object $row): self
    {
        return new self(
            table: self::text($row->table_name ?? null),
            name: self::text($row->column_name ?? null),
            dataType: self::text($row->data_type ?? null),
            length: is_numeric($row->len ?? null) ? (int) $row->len : -1,
            nullable: ($row->is_nullable ?? 'YES') !== 'NO',
            default: isset($row->column_default) && is_scalar($row->column_default)
                ? (string) $row->column_default
                : null,
        );
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
