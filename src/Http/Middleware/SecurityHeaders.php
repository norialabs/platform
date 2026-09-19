<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
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

        if (! Config::boolean('platform.http.security_headers.enabled', true)) {
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
            $this->cspHeader() => $this->policy($response),
        ];

        $permissions = Config::string('platform.http.security_headers.permissions_policy', '');

        if ($permissions !== '') {
            $headers['Permissions-Policy'] = $permissions;
        }

        $maxAge = Config::integer('platform.http.security_headers.hsts_max_age', 0);

        if ($request->isSecure() && $maxAge > 0) {
            $headers['Strict-Transport-Security'] = "max-age={$maxAge}; includeSubDomains";
        }

        return $headers;
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
        return Config::boolean('platform.http.security_headers.report_only', false)
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';
    }

    private function policy(Response $response): string
    {
        $directives = Config::array('platform.http.security_headers.directives', []);

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

        $report = Config::get('platform.http.security_headers.report_uri');

        if (is_string($report) && $report !== '') {
            $parts[] = 'report-uri '.$report;
        }

        return implode('; ', $parts);
    }
}
