<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Db;

use Illuminate\Database\Connection;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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

        return self::parse($settings);
    }

    /**
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

        $credentials = self::parse($credentials);

        $username = $credentials['username'] ?? null;

        if (is_string($username) && $username !== '') {
            $settings['username'] = $username;
            $settings['password'] = is_string($credentials['password'] ?? null) ? $credentials['password'] : '';
        }

        return $settings;
    }

    /**
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
