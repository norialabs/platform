<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Auth;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use NoriaLabs\Platform\Concerns\BelongsToWorkspace;

/**
 * Sanctum's token, carrying a real tenant column.
 *
 * The workspace is parsed out of the abilities on the way in and written to
 * the column, and a token that names none never reaches the table. Enforced
 * here rather than at the caller, so no controller, job or command can mint
 * a token spanning every workspace by forgetting a line.
 *
 * Register it with Sanctum::usePersonalAccessTokenModel() in the host. The
 * package does not, because a product may not use Sanctum at all.
 *
 * @property string $id
 * @property string|null $workspace_id
 * @property list<string> $abilities
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use BelongsToWorkspace;
    use HasUuids;

    /**
     * Sanctum names four fillable columns, and a non-empty fillable list
     * wins over a guarded one - so the tokenable morph would be silently
     * dropped from every insert. Cleared here to put guarded back in
     * charge, which is also how the rest of the package's models behave.
     *
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
