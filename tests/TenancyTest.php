<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use NoriaLabs\Platform\Http\Middleware\SetWorkspaceContext;
use NoriaLabs\Platform\Tenancy\Tenancy;
use NoriaLabs\Platform\Tenancy\TenancyMissing;
use NoriaLabs\Platform\Tests\Fixtures\StubWorkspaces;
use NoriaLabs\Platform\Tests\Fixtures\Widget;
use Symfony\Component\HttpFoundation\Response;

function postgresOnly(): void
{
    if (DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Tenancy is enforced by Postgres session settings.');
    }
}

function guc(string $name): string
{
    return (string) DB::scalar("select current_setting('{$name}', true)");
}

beforeEach(fn () => postgresOnly());

it('puts the workspace on the connection where the policies can read it', function (): void {
    app(Tenancy::class)->set('01a0b000-0000-7000-8000-000000000001');

    expect(guc('app.workspace_id'))->toBe('01a0b000-0000-7000-8000-000000000001');
});

it('knows which workspace it is in, and says so rather than guessing', function (): void {
    $tenancy = app(Tenancy::class);

    expect($tenancy->id())->toBeNull();

    $tenancy->set('01a0b000-0000-7000-8000-000000000001');

    expect($tenancy->idOrFail())->toBe('01a0b000-0000-7000-8000-000000000001');
});

it('refuses to guess a workspace when none is set', function (): void {
    app(Tenancy::class)->idOrFail();
})->throws(TenancyMissing::class);

it('puts back the workspace it borrowed', function (): void {
    $tenancy = app(Tenancy::class);
    $tenancy->set('01a0b000-0000-7000-8000-000000000001');

    $tenancy->run('01a0b000-0000-7000-8000-000000000002', function () use ($tenancy): void {
        expect($tenancy->id())->toBe('01a0b000-0000-7000-8000-000000000002');
    });

    expect($tenancy->id())->toBe('01a0b000-0000-7000-8000-000000000001');
});

it('puts the workspace back even when the work throws', function (): void {
    $tenancy = app(Tenancy::class);
    $tenancy->set('01a0b000-0000-7000-8000-000000000001');

    try {
        $tenancy->run('01a0b000-0000-7000-8000-000000000002', fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
        // The point is what the connection holds afterwards.
    }

    expect($tenancy->id())->toBe('01a0b000-0000-7000-8000-000000000001');
});

/*
 * The whole reason clear() resets a list rather than one setting. A pooled
 * connection still holding staff_read reads every workspace for every
 * request after, and this is the drift that had already happened between
 * two of our products.
 */
it('clears every widening setting on the way out, not only the workspace', function (): void {
    $tenancy = app(Tenancy::class);
    $tenancy->set('01a0b000-0000-7000-8000-000000000001');

    $tenancy->asStaff(function (): void {
        expect(guc('app.staff_read'))->toBe('on');
    });

    $tenancy->clear();

    expect(guc('app.staff_read'))->toBe('');
    expect(guc('app.platform_write'))->toBe('');
    expect(guc('app.workspace_id'))->toBe('');
});

/*
 * A setting clear() does not know about is a setting that outlives the
 * request that set it, so a new one cannot be introduced without being
 * added to the list that resets it.
 */
it('refuses a setting nobody has arranged to clear', function (): void {
    app(Tenancy::class)->withGuc(['app.undeclared' => 'on'], fn () => null);
})->throws(TenancyMissing::class, 'app.undeclared');

it('accepts a setting once the product declares it', function (): void {
    config(['platform.tenancy.gucs' => [...config('platform.tenancy.gucs'), 'app.portal_token']]);

    $seen = app(Tenancy::class)->withGuc(['app.portal_token' => 'abc'], fn (): string => guc('app.portal_token'));

    expect($seen)->toBe('abc');
});

it('takes back a widening setting after the work that needed it', function (): void {
    $tenancy = app(Tenancy::class);

    $tenancy->asPlatform(fn () => expect(guc('app.platform_write'))->toBe('on'));

    expect(guc('app.platform_write'))->toBe('');
});

it('reads the workspace setting name from config, so a product can rename it', function (): void {
    config([
        'platform.tenancy.workspace_guc' => 'app.tenant',
        'platform.tenancy.gucs' => ['app.tenant'],
    ]);

    app(Tenancy::class)->set('01a0b000-0000-7000-8000-000000000001');

    expect(guc('app.tenant'))->toBe('01a0b000-0000-7000-8000-000000000001');
});

describe('the middleware', function (): void {
    afterEach(fn () => StubWorkspaces::$workspaceId = null);

    it('puts the workspace the product resolved onto the connection', function (): void {
        StubWorkspaces::$workspaceId = '01a0b000-0000-7000-8000-00000000000a';

        app(SetWorkspaceContext::class)->handle(Request::create('/'), fn () => new Response);

        expect(guc('app.workspace_id'))->toBe('01a0b000-0000-7000-8000-00000000000a');
    });

    it('leaves the connection alone when the request belongs to no workspace', function (): void {
        app(SetWorkspaceContext::class)->handle(Request::create('/'), fn () => new Response);

        expect(app(Tenancy::class)->id())->toBeNull();
    });

    /*
     * Not optional. The setting is session scoped, so a pooled connection
     * would serve the next caller somebody else's data.
     */
    it('takes the workspace back off on the way out', function (): void {
        StubWorkspaces::$workspaceId = '01a0b000-0000-7000-8000-00000000000a';

        $request = Request::create('/');
        app(SetWorkspaceContext::class)->handle($request, fn () => new Response);
        app(SetWorkspaceContext::class)->terminate($request, new Response);

        expect(guc('app.workspace_id'))->toBe('');
    });
});

describe('a product with no tenants', function (): void {
    it('writes no settings at all when tenancy is turned off', function (): void {
        config(['platform.tenancy.enabled' => false]);

        app(Tenancy::class)->set('01a0b000-0000-7000-8000-00000000000a');

        expect(guc('app.workspace_id'))->toBe('');
    });
});

describe('stamping a row', function (): void {
    beforeEach(function (): void {
        Schema::create('widgets', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable();
            $table->string('name', 64);
        });
    });

    /*
     * Without this every insert has to name its own workspace, and the one
     * that forgets is refused by the policy - or writes an orphan nobody
     * can read again.
     */
    it('puts the current workspace on a row as it is written', function (): void {
        app(Tenancy::class)->run('01a0b000-0000-7000-8000-00000000000a', function (): void {
            Widget::query()->create(['name' => 'ours']);
        });

        expect(DB::table('widgets')->value('workspace_id'))->toBe('01a0b000-0000-7000-8000-00000000000a');
    });

    it('leaves a workspace the caller named alone', function (): void {
        app(Tenancy::class)->run('01a0b000-0000-7000-8000-00000000000a', function (): void {
            Widget::query()->create(['name' => 'theirs', 'workspace_id' => '01a0b000-0000-7000-8000-00000000000b']);
        });

        expect(DB::table('widgets')->value('workspace_id'))->toBe('01a0b000-0000-7000-8000-00000000000b');
    });

    it('stamps the column the product renamed it to', function (): void {
        Schema::drop('widgets');
        Schema::create('widgets', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('name', 64);
        });

        config([
            'platform.tenancy.column' => 'tenant_id',
            'platform.tenancy.gucs' => ['app.workspace_id'],
        ]);

        app(Tenancy::class)->run('01a0b000-0000-7000-8000-00000000000a', function (): void {
            Widget::query()->create(['name' => 'ours']);
        });

        expect(DB::table('widgets')->value('tenant_id'))->toBe('01a0b000-0000-7000-8000-00000000000a');
    });
});
