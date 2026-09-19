<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Log;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use NoriaLabs\Platform\Contracts\LogContext;
use Throwable;

final class Logger
{
    private function __construct() {}

    /** @param array<array-key, mixed> $context */
    public static function app(string $message, array $context = [], string $level = 'info'): void
    {
        self::write('app', $message, $context, $level);
    }

    /** @param array<array-key, mixed> $context */
    public static function auth(string $message, array $context = [], string $level = 'info'): void
    {
        self::write('auth', $message, $context, $level);
    }

    /** @param array<array-key, mixed> $context */
    public static function backup(string $message, array $context = [], string $level = 'info'): void
    {
        self::write('backup', $message, $context, $level);
    }

    /**
     * @param  array<array-key, mixed>  $context
     */
    public static function exception(string $message, Throwable $e, array $context = [], string $level = 'error'): void
    {
        self::write(
            Config::string('noria.log.exception_channel', 'app'),
            $message,
            [...$context, 'exception' => $e],
            $level,
        );
    }

    /**
     * @param  array<array-key, mixed>  $context
     */
    public static function create(string $channel, string $message, array $context = [], string $level = 'info'): void
    {
        self::write($channel, $message, $context, $level);
    }

    /** @param array<array-key, mixed> $context */
    private static function write(string $channel, string $message, array $context, string $level): void
    {
        $scrubbed = Redactor::scrub([...self::ambient(), ...$context]);

        if (Config::get('logging.channels.'.$channel) === null) {
            Log::log($level, $message, $scrubbed);

            return;
        }

        Log::channel($channel)->log($level, $message, $scrubbed);
    }

    /** @return array<string, mixed> */
    private static function ambient(): array
    {
        if (! App::bound(LogContext::class)) {
            return [];
        }

        $context = App::make(LogContext::class);

        return $context instanceof LogContext ? $context->capture() : [];
    }
}
