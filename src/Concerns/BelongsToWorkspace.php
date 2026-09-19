<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use NoriaLabs\Platform\Tenancy\Tenancy;

/**
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
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForWorkspace(Builder $query, string $workspaceId): Builder
    {
        return $query->where(Config::string('noria.tenancy.column', 'workspace_id'), $workspaceId);
    }
}
