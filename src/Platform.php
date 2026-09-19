<?php

declare(strict_types=1);

namespace NoriaLabs\Platform;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use NoriaLabs\Platform\Audit\AuditLog;

/**
 * The package's configuration surface: which models it reads through, what
 * its tables are called, and which connection they live on.
 *
 * Models are swappable because a host that cannot add a relation, a scope or
 * a trait to a package's model ends up forking the package. Call the setters
 * from a service provider's register().
 */
final class Platform
{
    /** @var class-string<AuditLog> */
    private static string $auditLogModel = AuditLog::class;

    public static function useAuditLogModel(string $model): void
    {
        if (! is_a($model, AuditLog::class, allow_string: true)) {
            throw new InvalidArgumentException($model.' must extend '.AuditLog::class.'.');
        }

        self::$auditLogModel = $model;
    }

    /** @return class-string<AuditLog> */
    public static function auditLogModel(): string
    {
        return self::$auditLogModel;
    }

    /**
     * The name of one of the platform's tables: the explicit override if the
     * host set one, otherwise the prefix. Read by the models and the
     * migrations, so the two cannot disagree about where a table lives.
     */
    public static function table(string $name): string
    {
        $configured = Config::get('platform.tables.'.$name);

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return Config::string('platform.table_prefix', '').$name;
    }

    public static function connection(): ?string
    {
        $connection = Config::get('platform.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /** Returns every model to its default. For tests, and for nothing else. */
    public static function forgetModels(): void
    {
        self::$auditLogModel = AuditLog::class;
    }
}
