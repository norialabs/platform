<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Concerns;

use RuntimeException;

trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(fn (): never => throw new RuntimeException(static::class.' is append-only.'));
        static::deleting(fn (): never => throw new RuntimeException(static::class.' is append-only.'));
    }
}
