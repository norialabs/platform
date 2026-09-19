<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use NoriaLabs\Platform\Contracts\WorkspaceResolver;
use NoriaLabs\Platform\Tenancy\Tenancy;
use Symfony\Component\HttpFoundation\Response;

class SetWorkspaceContext
{
    public function __construct(
        private Tenancy $tenancy,
        private WorkspaceResolver $resolver,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $workspaceId = $this->resolver->resolve($request);

        if ($workspaceId !== null) {
            $this->tenancy->set($workspaceId);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->tenancy->clear();
    }
}
