<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use NoriaLabs\Platform\Contracts\PermissionCeiling;
use NoriaLabs\Platform\Contracts\PrincipalResolver;
use NoriaLabs\Platform\Contracts\RoleRepository;
use NoriaLabs\Platform\Contracts\WorkspaceResolver;
use NoriaLabs\Platform\Platform;
use NoriaLabs\Platform\PlatformServiceProvider;
use NoriaLabs\Platform\Tests\Fixtures\Action;
use NoriaLabs\Platform\Tests\Fixtures\Resource;
use NoriaLabs\Platform\Tests\Fixtures\StubCeiling;
use NoriaLabs\Platform\Tests\Fixtures\StubPrincipals;
use NoriaLabs\Platform\Tests\Fixtures\StubRoles;
use NoriaLabs\Platform\Tests\Fixtures\StubWorkspaces;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Platform::forgetModels();

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [PlatformServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Point NORIA_TEST_PG at a scratch Postgres to exercise jsonb, the
        // row level security policies and the session settings they read.
        // Without it the suite runs on SQLite and those assertions skip.
        if (($url = env('NORIA_TEST_PG')) !== null) {
            $app['config']->set('database.connections.noria_pg', [
                'driver' => 'pgsql',
                'url' => $url,
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ]);
            $app['config']->set('database.default', 'noria_pg');

            // A second connection whose role may bypass row level security.
            // Without it the dump tests skip rather than pass against a
            // file that would have come back empty.
            if (($admin = env('NORIA_TEST_PG_ADMIN')) !== null) {
                $app['config']->set('database.connections.noria_pg_admin', [
                    'driver' => 'pgsql',
                    'url' => $admin,
                    'charset' => 'utf8',
                    'prefix' => '',
                    'search_path' => 'public',
                    'sslmode' => 'prefer',
                ]);
            }
        } else {
            $app['config']->set('database.default', 'testing');

            // Row level security is a Postgres feature, and the session
            // settings it reads do not exist elsewhere. Saying so here is
            // truthful and lets the rest of the suite run on SQLite.
            $app['config']->set('noria.tenancy.enabled', false);
        }

        $app['config']->set('cache.default', 'array');
        $app['config']->set('noria.identity.hash_key', 'a-test-hash-key');
        $app['config']->set('noria.rbac.resources', Resource::class);
        $app['config']->set('noria.rbac.actions', Action::class);

        $app->bind(WorkspaceResolver::class, StubWorkspaces::class);
        $app->bind(PrincipalResolver::class, StubPrincipals::class);
        $app->bind(RoleRepository::class, StubRoles::class);
        $app->bind(PermissionCeiling::class, StubCeiling::class);
    }
}
