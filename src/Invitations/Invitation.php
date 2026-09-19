<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Invitations;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use NoriaLabs\Platform\Concerns\BelongsToWorkspace;
use NoriaLabs\Platform\Platform;

/**
 * @property string $id
 * @property string|null $workspace_id
 * @property string $channel
 * @property string $destination_hash
 * @property string|null $destination_hint
 * @property string $role
 * @property string $token_hash
 * @property string|null $invited_by
 * @property string|null $accepted_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 */
class Invitation extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $guarded = ['id'];

    public function getTable(): string
    {
        return Platform::table('invitations');
    }

    public function getConnectionName(): ?string
    {
        return Platform::connection() ?? parent::getConnectionName();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    public function hasExpired(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isPast();
    }
}
