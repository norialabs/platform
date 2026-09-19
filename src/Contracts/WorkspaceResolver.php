<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

use Illuminate\Http\Request;

/**
 * How this product decides which workspace a request belongs to.
 *
 * It is the host's because the answer lives in the host's models: a
 * membership, a token's abilities, a subdomain. The one rule the package
 * holds it to is that the answer comes from the credential - a request that
 * can name its own workspace has made row level security an opt-in.
 */
interface WorkspaceResolver
{
    public function resolve(Request $request): ?string;
}
