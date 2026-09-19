<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tests\Fixtures;

use Illuminate\Http\Request;
use NoriaLabs\Platform\Contracts\WorkspaceResolver;

class StubWorkspaces implements WorkspaceResolver
{
    public static ?string $workspaceId = null;

    public function resolve(Request $request): ?string
    {
        return self::$workspaceId;
    }
}
