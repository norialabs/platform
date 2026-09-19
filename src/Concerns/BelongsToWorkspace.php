<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use NoriaLabs\Platform\Tenancy\Tenancy;

/**
 * Stamps the current workspace onto a row as it is created.
 *
 * Without it every insert has to name its own workspace, and the one that
 * forgets is refused by the policy's with check - or, on a nullable column,
 * writes an orphan nobody can read again.
 *
 * @property string|null $workspace_id
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::creating(function (Model $model): void {
            $column = Config::string('noria.tenancy.column', 'workspace_id');

            if (blank($model->getAttribute($column))) {
                $workspaceId = app(Tenancy::class)->id();

                if ($workspaceId !== null) {
                    $model->setAttribute($column, $workspaceId);
                }
            }
        });
    }

    /**
     * Scoped to one workspace explicitly, for a report that crosses several.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForWorkspace(Builder $query, string $workspaceId): Builder
    {
        return $query->where(Config::string('noria.tenancy.column', 'workspace_id'), $workspaceId);
    }
}
