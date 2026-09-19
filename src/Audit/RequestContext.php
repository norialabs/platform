<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Audit;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * What can be said about the caller of the current request, or nothing at
 * all when the caller is the scheduler.
 *
 * The request id is minted once per request and shared, so a trail row and
 * a log line written seconds apart can be joined afterwards.
 */
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
