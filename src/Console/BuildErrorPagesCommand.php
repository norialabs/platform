<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Throwable;

class BuildErrorPagesCommand extends Command
{
    protected $signature = 'noria:build-error-pages';

    protected $description = 'Render the gateway error views to static files the edge can serve';

    public function handle(): int
    {
        /** @var list<int> $codes */
        $codes = array_values(array_filter(
            Config::array('noria.errors.codes', [502, 504]),
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
            $view = Config::string('noria.errors.view', 'errors.').$code;

            if (! View::exists($view)) {
                $this->components->error("There is no [{$view}] view to render.");

                return self::FAILURE;
            }

            File::put(public_path("{$code}.html"), View::file(View::getFinder()->find($view))->render());
            $this->components->twoColumnDetail("public/{$code}.html", 'written');
        }

        return self::SUCCESS;
    }

    private function stylesheet(): ?string
    {
        $asset = Config::get('noria.errors.stylesheet');

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
