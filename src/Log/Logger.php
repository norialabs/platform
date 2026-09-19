<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Log;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use NoriaLabs\Platform\Contracts\LogContext;
use Throwable;

/**
 * Log::info with the context scrubbed first.
 *
 * Used instead of the facade everywhere a payload could carry a credential
 * or somebody's phone number, which in these products is almost everywhere.
 */
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

    /** @param array<array-key, mixed> $context */
    public static function exception(string $message, Throwable $e, array $context = [], string $level = 'error'): void
    {
        self::write('app', $message, [
            ...$context,
            'exception' => $e::class,
            'reason' => $e->getMessage(),
            'at' => $e->getFile().':'.$e->getLine(),
        ], $level);
    }

    /**
     * Any other channel the product defined.
     *
     * Named rather than magic: __callStatic would read better at the call
     * site and is invisible to static analysis, which every product in
     * this estate runs at max.
     *
     * @param  array<array-key, mixed>  $context
     */
    public static function create(string $channel, string $message, array $context = [], string $level = 'info'): void
    {
        self::write($channel, $message, $context, $level);
    }

    /** @param array<array-key, mixed> $context */
    private static function write(string $channel, string $message, array $context, string $level): void
    {
        // Ambient first, so a caller naming the same key wins: the line
        // knows more about itself than the request does.
        $scrubbed = Redactor::scrub([...self::ambient(), ...$context]);

        // Asking the log manager for a channel that is not defined throws,
        // which it catches by writing an EMERGENCY entry alongside the real
        // one. Falling back keeps a product that has not defined our
        // channels to a single line.
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
