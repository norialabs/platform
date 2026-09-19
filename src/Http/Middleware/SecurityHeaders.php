<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! Config::boolean('noria.http.security_headers.enabled', true)) {
            return $response;
        }

        foreach ($this->headers($request, $response) as $name => $value) {
            $response->headers->set($name, $value, replace: false);
        }

        return $response;
    }

    /** @return array<string, string> */
    private function headers(Request $request, Response $response): array
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => $this->framed($response) ? 'SAMEORIGIN' : 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            $this->cspHeader() => $this->policy($request, $response),
        ];

        $permissions = Config::string('noria.http.security_headers.permissions_policy', '');

        if ($permissions !== '') {
            $headers['Permissions-Policy'] = $permissions;
        }

        $maxAge = Config::integer('noria.http.security_headers.hsts_max_age', 0);

        if ($request->isSecure() && $maxAge > 0) {
            $headers['Strict-Transport-Security'] = "max-age={$maxAge}; includeSubDomains";
        }

        return $headers;
    }

    private function api(Request $request): bool
    {
        $paths = Config::array('noria.http.security_headers.api.paths', []);

        foreach ($paths as $path) {
            if (is_string($path) && $request->is($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $directives
     * @return array<mixed>
     */
    private function withDevOrigin(array $directives): array
    {
        $origin = $this->devOrigin();

        if ($origin === null) {
            return $directives;
        }

        foreach (Config::array('noria.http.security_headers.dev_origin_directives', []) as $name) {
            if (! is_string($name) || ! isset($directives[$name])) {
                continue;
            }

            $directives[$name] = [...(array) $directives[$name], $origin];
        }

        $directives['connect-src'] = [
            ...(array) ($directives['connect-src'] ?? []),
            $origin,
            str_replace('http', 'ws', $origin),
        ];

        return $directives;
    }

    private function devOrigin(): ?string
    {
        if (! App::environment('local') || ! Config::boolean('noria.http.security_headers.dev_origin', true)) {
            return null;
        }

        if (! Vite::isRunningHot()) {
            return null;
        }

        $hot = trim((string) @file_get_contents(public_path('hot')));

        return $hot === '' ? null : rtrim($hot, '/');
    }

    private function framed(Response $response): bool
    {
        return str_contains((string) $response->headers->get('Content-Type'), 'application/pdf');
    }

    private function cspHeader(): string
    {
        return Config::boolean('noria.http.security_headers.report_only', false)
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';
    }

    private function policy(Request $request, Response $response): string
    {
        $directives = $this->api($request)
            ? Config::array('noria.http.security_headers.api.directives', [])
            : $this->withDevOrigin(Config::array('noria.http.security_headers.directives', []));

        if ($this->framed($response)) {
            $directives['frame-ancestors'] = ["'self'"];
        }

        $parts = [];

        foreach ($directives as $name => $values) {
            $sources = [];

            foreach ((array) $values as $value) {
                if (is_scalar($value)) {
                    $sources[] = (string) $value;
                }
            }

            $parts[] = trim((string) $name.' '.implode(' ', $sources));
        }

        $report = Config::get('noria.http.security_headers.report_uri');

        if (is_string($report) && $report !== '') {
            $parts[] = 'report-uri '.$report;
        }

        return implode('; ', $parts);
    }
}
