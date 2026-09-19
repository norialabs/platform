<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Auth;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use NoriaLabs\Platform\Concerns\BelongsToWorkspace;

/**
 * @property string $id
 * @property string|null $workspace_id
 * @property list<string> $abilities
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use BelongsToWorkspace;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $token): void {
            $abilities = $token->abilities;

            $token->workspace_id = TokenAbilities::workspaceIn(is_array($abilities) ? $abilities : [])
                ?? throw new UnscopedToken;
        });
    }
}
