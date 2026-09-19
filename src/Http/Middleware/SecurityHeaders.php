<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers every response carries. Directives are config, because a
 * product that embeds a widget or loads a third-party script needs to relax
 * one of them without dropping the whole middleware.
 *
 * Set rather than replaced: a route that has already decided its own policy
 * knows something this does not.
 */
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

    /**
     * An API answers with data, so it needs no origin at all - not even
     * its own. A policy wide enough for the pages is far wider than the
     * routes that only ever return JSON.
     */
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
     * The dev server serves assets from its own origin while it is hot, so
     * a policy naming only 'self' blocks every script the page needs. Only
     * in local, and only while the hot file exists.
     *
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

    /**
     * A PDF the browser renders in a frame cannot be served with
     * frame-ancestors none, so that one document type relaxes to same origin.
     */
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
