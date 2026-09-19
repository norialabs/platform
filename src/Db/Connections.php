<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Db;

use Illuminate\Database\Connection;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Resolving the settings a dump or a restore runs against.
 *
 * The application's own role is usually the wrong one for both: on a
 * database with row level security forced, pg_dump as that role sees no
 * rows, and a restore needs to create and grant. The admin connection
 * supplies a role that may do those things and nothing else.
 */
final class Connections
{
    private function __construct() {}

    /** @return array<string, mixed> */
    public static function settings(?string $name = null): array
    {
        $name ??= Config::string('database.default');
        $settings = Config::get('database.connections.'.$name);

        if (! is_array($settings)) {
            throw new RuntimeException("Unknown database connection [{$name}].");
        }

        // An app configured with a single url has no host, port or database
        // key of its own, and the command line tools take those as separate
        // arguments. Parsed here so everything downstream sees one shape.
        return self::parse($settings);
    }

    /**
     * The same database, reached as the admin role where the host named one.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function asAdmin(array $settings): array
    {
        $admin = Config::get('noria.db.admin_connection');

        if (! is_string($admin) || $admin === '') {
            return $settings;
        }

        $credentials = Config::get('database.connections.'.$admin);

        if (! is_array($credentials)) {
            return $settings;
        }

        // Parsed for the same reason settings() is: the admin connection is
        // as likely to be a url as the application's own.
        $credentials = self::parse($credentials);

        $username = $credentials['username'] ?? null;

        if (is_string($username) && $username !== '') {
            $settings['username'] = $username;
            $settings['password'] = is_string($credentials['password'] ?? null) ? $credentials['password'] : '';
        }

        return $settings;
    }

    /**
     * A throwaway connection, named so two of them never collide, and the
     * caller's to disconnect.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function open(string $purpose, array $settings): Connection
    {
        /** @var Connection */
        return DB::connectUsing('noria-'.$purpose.'-'.bin2hex(random_bytes(3)), $settings, force: true);
    }

    /**
     * @param  array<mixed>  $settings
     * @return array<string, mixed>
     */
    private static function parse(array $settings): array
    {
        $keyed = [];

        foreach ($settings as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return (new ConfigurationUrlParser)->parseConfiguration($keyed);
    }

    /** @param array<string, mixed> $settings */
    public static function value(array $settings, string $key, string $default = ''): string
    {
        $value = $settings[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }

    /** @param array<string, mixed> $settings */
    public static function driver(array $settings): string
    {
        return self::value($settings, 'driver');
    }
}
