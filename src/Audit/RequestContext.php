<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Audit;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RequestContext
{
    private ?string $id = null;

    public function __construct(private ?Request $request = null) {}

    public function id(): string
    {
        return $this->id ??= (string) ($this->header('X-Request-Id') ?? Str::uuid7());
    }

    public function ip(): ?string
    {
        return $this->request?->ip();
    }

    public function userAgent(): ?string
    {
        $agent = $this->request?->userAgent();

        return $agent === null ? null : Str::limit($agent, 512, '');
    }

    private function header(string $name): ?string
    {
        $value = $this->request?->headers->get($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
