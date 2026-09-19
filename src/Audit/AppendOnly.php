<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Audit;

use RuntimeException;

/**
 * A row that cannot be changed or removed once written. The database trigger
 * from Rls::appendOnly() is the real guard; this one turns a mistake into a
 * clear exception instead of a restrict_violation from Postgres.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(fn (): never => throw new RuntimeException(static::class.' is append-only.'));
        static::deleting(fn (): never => throw new RuntimeException(static::class.' is append-only.'));
    }
}
