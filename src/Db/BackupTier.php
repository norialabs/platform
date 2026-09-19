<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Db;

use Illuminate\Support\Facades\Config;

/**
 * An hourly dump answers the mistake somebody made this morning; a daily
 * one answers the corruption nobody noticed for a fortnight. Keeping a
 * fortnight of hourlies to get the second costs fourteen times the storage.
 */
enum BackupTier: string
{
    case Hourly = 'hourly';
    case Daily = 'daily';

    public function prefix(): string
    {
        $prefix = Config::string('noria.db.tiers.'.$this->value.'.prefix', 'backups/'.$this->value);

        return trim($prefix, '/');
    }

    /** How long a dump in this tier is kept, in hours. */
    public function retentionHours(): int
    {
        return match ($this) {
            self::Hourly => max(1, Config::integer('noria.db.tiers.hourly.hours', 48)),
            self::Daily => max(1, Config::integer('noria.db.tiers.daily.days', 30)) * 24,
        };
    }
}
