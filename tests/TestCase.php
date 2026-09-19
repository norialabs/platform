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
        if (($url = env('NORIA_TEST_PG')) !== null) {
            $app['config']->set('database.connections.noria_pg', [
                'driver' => 'pgsql',
                'url' => $url,
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
                'timezone' => 'UTC',
            ]);
            $app['config']->set('database.default', 'noria_pg');

            if (($admin = env('NORIA_TEST_PG_ADMIN')) !== null) {
                $app['config']->set('database.connections.noria_pg_admin', [
                    'driver' => 'pgsql',
                    'url' => $admin,
                    'charset' => 'utf8',
                    'prefix' => '',
                    'search_path' => 'public',
                    'sslmode' => 'prefer',
                    'timezone' => 'UTC',
                ]);
            }
        } else {
            $app['config']->set('database.default', 'testing');

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
