<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

use Illuminate\Http\Request;

interface WorkspaceResolver
{
    public function resolve(Request $request): ?string;
}
