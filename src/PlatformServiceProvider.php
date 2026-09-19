<?php

declare(strict_types=1);

namespace NoriaLabs\Platform;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use NoriaLabs\Platform\Audit\AuditRecorder;
use NoriaLabs\Platform\Audit\RequestContext;
use NoriaLabs\Platform\Auth\Otp;
use NoriaLabs\Platform\Auth\SocialState;
use NoriaLabs\Platform\Console\BackupCommand;
use NoriaLabs\Platform\Console\PruneOtpCommand;
use NoriaLabs\Platform\Console\RebuildCommand;
use NoriaLabs\Platform\Console\RestoreCommand;
use NoriaLabs\Platform\Console\SchedulerHealthyCommand;
use NoriaLabs\Platform\Console\SchedulerHeartbeatCommand;
use NoriaLabs\Platform\Console\TenancyCheckCommand;
use NoriaLabs\Platform\Contracts\PermissionAction;
use NoriaLabs\Platform\Contracts\PermissionCeiling;
use NoriaLabs\Platform\Contracts\PermissionResource;
use NoriaLabs\Platform\Contracts\PrincipalResolver;
use NoriaLabs\Platform\Contracts\RoleRepository;
use NoriaLabs\Platform\Db\Backup;
use NoriaLabs\Platform\Db\DumperFactory;
use NoriaLabs\Platform\Db\Rebuild;
use NoriaLabs\Platform\Db\Restore;
use NoriaLabs\Platform\Rbac\PermissionResolver;
use NoriaLabs\Platform\Tenancy\Tenancy;

class PlatformServiceProvider extends ServiceProvider
{
    /** The gate every product authorises through. */
    public const GATE = 'workspace-permission';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/platform.php', 'platform');

        // Scoped, not singleton: a long-lived worker serves many workspaces,
        // and a connection that remembers the last one is the whole problem.
        $this->app->scoped(Tenancy::class);
        $this->app->scoped(PermissionResolver::class, fn (Application $app): PermissionResolver => new PermissionResolver(
            $app->make(PrincipalResolver::class),
            $app->make(RoleRepository::class),
            $app->bound(PermissionCeiling::class) ? $app->make(PermissionCeiling::class) : null,
        ));

        $this->app->scoped(RequestContext::class, fn (Application $app): RequestContext => new RequestContext(
            $app->bound(Request::class) ? $app->make(Request::class) : null,
        ));

        $this->app->scoped(AuditRecorder::class);
        $this->app->singleton(Otp::class);
        $this->app->singleton(SocialState::class);
        $this->app->singleton(DumperFactory::class);
        $this->app->singleton(Backup::class);
        $this->app->singleton(Restore::class);
        $this->app->singleton(Rebuild::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                BackupCommand::class,
                RestoreCommand::class,
                TenancyCheckCommand::class,
                PruneOtpCommand::class,
                SchedulerHealthyCommand::class,
                SchedulerHeartbeatCommand::class,
                RebuildCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/platform.php' => config_path('platform.php'),
            ], 'platform-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'platform-migrations');
        }

        // Loaded from the package unless the host published them. Doing both
        // creates every table twice, which fails on the second CREATE and
        // leaves a half-migrated database behind.
        if (Config::boolean('platform.load_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $this->registerGate();
    }

    /**
     * One gate for the whole application: the catalogue is the product's
     * enums, the grant is the document on its roles, and this is where the
     * two meet.
     *
     *     Gate::authorize(PlatformServiceProvider::GATE, [Resource::Deal, Action::Update]);
     *
     * Registered only when the host has bound the two contracts it needs. A
     * product that has not adopted RBAC yet should get its own failure, not
     * one from inside this package.
     */
    private function registerGate(): void
    {
        if (! $this->app->bound(PrincipalResolver::class) || ! $this->app->bound(RoleRepository::class)) {
            return;
        }

        Gate::define(self::GATE, fn (
            Authenticatable $user,
            PermissionResource $resource,
            PermissionAction $action,
        ): bool => $this->app->make(PermissionResolver::class)->allows($user, $resource, $action));
    }
}
