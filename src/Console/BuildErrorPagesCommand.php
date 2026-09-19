<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Throwable;

/**
 * Renders the error views to self-contained files the edge can serve.
 *
 * A 502 or a 504 means the application is not answering, so the page for
 * it cannot be rendered by the application. The stylesheet is inlined for
 * the same reason: at that moment nothing is serving assets either.
 */
class BuildErrorPagesCommand extends Command
{
    protected $signature = 'platform:build-error-pages';

    protected $description = 'Render the gateway error views to static files the edge can serve';

    public function handle(): int
    {
        /** @var list<int> $codes */
        $codes = array_values(array_filter(
            Config::array('platform.errors.codes', [502, 504]),
            is_int(...),
        ));

        if ($codes === []) {
            $this->components->info('No error pages are configured.');

            return self::SUCCESS;
        }

        $css = $this->stylesheet();

        if ($css === null) {
            return self::FAILURE;
        }

        View::share('errorInlineCss', $css);

        foreach ($codes as $code) {
            $view = Config::string('platform.errors.view', 'errors.').$code;

            if (! View::exists($view)) {
                $this->components->error("There is no [{$view}] view to render.");

                return self::FAILURE;
            }

            // Resolved to a path and rendered as a file: the name is built
            // from config at runtime, and make() is typed for names known
            // at author time.
            File::put(public_path("{$code}.html"), View::file(View::getFinder()->find($view))->render());
            $this->components->twoColumnDetail("public/{$code}.html", 'written');
        }

        return self::SUCCESS;
    }

    private function stylesheet(): ?string
    {
        $asset = Config::get('platform.errors.stylesheet');

        if (! is_string($asset) || $asset === '') {
            return '';
        }

        try {
            return Vite::content($asset);
        } catch (Throwable) {
            $this->components->error("[{$asset}] is not built. Build the assets before this command.");

            return null;
        }
    }
}
