<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Audit;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use NoriaLabs\Platform\Concerns\AppendOnly;
use NoriaLabs\Platform\Platform;

/**
 * @property string $id
 * @property string|null $workspace_id
 * @property string|null $actor_id
 * @property string $actor_type
 * @property string $action
 * @property string|null $target_type
 * @property string|null $target_id
 * @property string|null $reason
 * @property array<string, mixed> $metadata
 */
class AuditLog extends Model
{
    use AppendOnly;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function getTable(): string
    {
        return Platform::table('audit_logs');
    }

    public function getConnectionName(): ?string
    {
        return Platform::connection() ?? parent::getConnectionName();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
