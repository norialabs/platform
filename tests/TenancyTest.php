<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use NoriaLabs\Platform\Http\Middleware\SetWorkspaceContext;
use NoriaLabs\Platform\Tenancy\Tenancy;
use NoriaLabs\Platform\Tenancy\TenancyMissing;
use NoriaLabs\Platform\Tests\Fixtures\CountWidgets;
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
    }

    expect($tenancy->id())->toBe('01a0b000-0000-7000-8000-000000000001');
});

it('clears every widening setting on the way out, not only the workspace', function (): void {
    $tenancy = app(Tenancy::class);
    $tenancy->set('01a0b000-0000-7000-8000-000000000001');

    $tenancy->asStaff(function (): void {
        expect(guc('app.staff_read'))->toBe('on');
    });

    $tenancy->clear();

    expect(guc('app.staff_read'))->toBe('');
    expect(guc('app.noria_write'))->toBe('');
    expect(guc('app.workspace_id'))->toBe('');
});

it('refuses a setting nobody has arranged to clear', function (): void {
    app(Tenancy::class)->withGuc(['app.undeclared' => 'on'], fn () => null);
})->throws(TenancyMissing::class, 'app.undeclared');

it('accepts a setting once the product declares it', function (): void {
    config(['noria.tenancy.gucs' => [...config('noria.tenancy.gucs'), 'app.portal_token']]);

    $seen = app(Tenancy::class)->withGuc(['app.portal_token' => 'abc'], fn (): string => guc('app.portal_token'));

    expect($seen)->toBe('abc');
});

it('takes back a widening setting after the work that needed it', function (): void {
    $tenancy = app(Tenancy::class);

    $tenancy->asPlatform(fn () => expect(guc('app.noria_write'))->toBe('on'));

    expect(guc('app.noria_write'))->toBe('');
});

it('reads the workspace setting name from config, so a product can rename it', function (): void {
    config([
        'noria.tenancy.workspace_guc' => 'app.tenant',
        'noria.tenancy.gucs' => ['app.tenant'],
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
        config(['noria.tenancy.enabled' => false]);

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
            'noria.tenancy.column' => 'tenant_id',
            'noria.tenancy.gucs' => ['app.workspace_id'],
        ]);

        app(Tenancy::class)->run('01a0b000-0000-7000-8000-00000000000a', function (): void {
            Widget::query()->create(['name' => 'ours']);
        });

        expect(DB::table('widgets')->value('tenant_id'))->toBe('01a0b000-0000-7000-8000-00000000000a');
    });
});

describe('a queued job', function (): void {
    beforeEach(fn () => CountWidgets::$ranInside = null);

    it('runs inside the workspace it was queued for', function (): void {
        app()->call([new CountWidgets('01a0b000-0000-7000-8000-00000000000a'), 'handle']);

        expect(CountWidgets::$ranInside)->toBe('01a0b000-0000-7000-8000-00000000000a');
    });

    it('leaves the connection as it found it', function (): void {
        app()->call([new CountWidgets('01a0b000-0000-7000-8000-00000000000a'), 'handle']);

        expect(app(Tenancy::class)->id())->toBeNull();
    });

    it('takes the lane the product configured', function (): void {
        config(['noria.tenancy.queue' => 'slow']);

        expect((new CountWidgets('01a0b000-0000-7000-8000-00000000000a'))->queue)->toBe('slow');
    });

    it('holds a lock per workspace rather than per job class', function (): void {
        $first = new CountWidgets('01a0b000-0000-7000-8000-00000000000a');
        $second = new CountWidgets('01a0b000-0000-7000-8000-00000000000b');

        expect($first->middleware()[0]->key)->not->toBe($second->middleware()[0]->key);
    });
});
