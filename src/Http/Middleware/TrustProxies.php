<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use NoriaLabs\Platform\Http\TrustedProxies;

/**
 * Laravel's own, pointed at noria.http.trusted_proxies.
 *
 * Every forwarded header is believed only from a hop on that list. Without
 * it a per-address rate limit counts the whole platform as one caller and
 * the audit trail records the balancer on every row.
 */
class TrustProxies extends Middleware
{
    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_AWS_ELB;

    public function __construct()
    {
        $this->proxies = TrustedProxies::from(Config::get('noria.http.trusted_proxies'));
    }
}
