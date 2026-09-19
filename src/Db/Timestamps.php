<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Db;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use NoriaLabs\Platform\Platform;
use RuntimeException;
use Throwable;

final class Timestamps
{
    private function __construct() {}

    public static function aware(): bool
    {
        return Config::string('noria.timestamps', 'tz') === 'tz';
    }

    public static function assertAligned(?string $connection = null): void
    {
        if (! self::aware()) {
            return;
        }

        $db = DB::connection($connection ?? Platform::connection());

        if ($db->getDriverName() !== 'pgsql') {
            return;
        }

        try {
            $setting = $db->scalar("select current_setting('TimeZone')");
        } catch (Throwable) {
            return;
        }

        if (! is_string($setting) || $setting === '') {
            return;
        }

        $session = $setting;

        $application = Config::string('app.timezone', 'UTC');

        if (self::offset($session) === self::offset($application)) {
            return;
        }

        throw new RuntimeException(
            "This connection's timezone is [{$session}] and the application runs on [{$application}], "
            ."so every timestamp written would be stored at the wrong moment. Set 'timezone' on the "
            ."connection in config/database.php, or set noria.timestamps to 'plain'."
        );
    }

    private static function offset(string $timezone): string
    {
        try {
            return (new \DateTimeZone($timezone))->getOffset(new \DateTimeImmutable) === 0
                ? '+00:00'
                : (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('P');
        } catch (Throwable) {
            return $timezone;
        }
    }
}
