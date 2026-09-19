<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Audit;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use NoriaLabs\Platform\Platform;
use NoriaLabs\Platform\Tenancy\Tenancy;

/**
 * The sole writer of the audit trail, injected wherever a decision needs one.
 */
class AuditRecorder
{
    public function __construct(
        private Tenancy $tenancy,
        private RequestContext $context,
    ) {}

    /** @param  array<string, mixed>  $metadata */
    public function record(
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $metadata = [],
        ?string $reason = null,
    ): ?AuditLog {
        if (! Config::boolean('platform.audit.enabled', true)) {
            return null;
        }

        $actorId = Auth::id();

        return Platform::auditLogModel()::query()->create([
            'workspace_id' => $this->tenancy->id(),
            'actor_id' => $actorId === null ? null : (string) $actorId,
            'actor_type' => $actorId === null ? 'system' : 'user',
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reason' => $reason,
            'metadata' => $metadata,
            'ip' => $this->context->ip(),
            'user_agent' => $this->context->userAgent(),
            'request_id' => $this->context->id(),
        ]);
    }
}
