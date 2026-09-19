<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use NoriaLabs\Platform\Tenancy\Invariants;
use NoriaLabs\Platform\Tenancy\Rls;
use NoriaLabs\Platform\Tenancy\Tenancy;

const WORKSPACE_A = '01a0b000-0000-7000-8000-00000000000a';
const WORKSPACE_B = '01a0b000-0000-7000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Row level security is a Postgres feature.');
    }

    // A superuser or a BYPASSRLS role ignores every policy, so a test run as
    // one would pass on tables that are wide open. Skipping is the honest
    // answer; the invariant check is what fails the deployment.
    if (Invariants::roleFailures() !== []) {
        $this->markTestSkipped('The connected role bypasses row level security.');
    }

    Schema::create('widgets', function ($table): void {
        $table->uuid('id')->primary();
        $table->uuid('workspace_id')->nullable();
        $table->string('name', 64);
    });

    Rls::protect('widgets', staffRead: true);
});

function insertWidget(string $id, ?string $workspaceId, string $name): void
{
    DB::table('widgets')->insert(['id' => $id, 'workspace_id' => $workspaceId, 'name' => $name]);
}

it('hides a row belonging to another workspace', function (): void {
    $tenancy = app(Tenancy::class);

    $tenancy->run(WORKSPACE_A, fn () => insertWidget('01a0b000-0000-7000-8000-000000000001', WORKSPACE_A, 'ours'));
    $tenancy->run(WORKSPACE_B, fn () => insertWidget('01a0b000-0000-7000-8000-000000000002', WORKSPACE_B, 'theirs'));

    $seen = $tenancy->run(WORKSPACE_A, fn (): array => DB::table('widgets')->pluck('name')->all());

    expect($seen)->toBe(['ours']);
});

it('refuses a write aimed at another workspace', function (): void {
    app(Tenancy::class)->run(WORKSPACE_A, function (): void {
        insertWidget('01a0b000-0000-7000-8000-000000000003', WORKSPACE_B, 'smuggled');
    });
})->throws(QueryException::class);

it('shows nothing at all when no workspace is set', function (): void {
    app(Tenancy::class)->run(WORKSPACE_A, fn () => insertWidget('01a0b000-0000-7000-8000-000000000004', WORKSPACE_A, 'ours'));

    expect(DB::table('widgets')->count())->toBe(0);
});

it('lets an operator read across every workspace', function (): void {
    $tenancy = app(Tenancy::class);

    $tenancy->run(WORKSPACE_A, fn () => insertWidget('01a0b000-0000-7000-8000-000000000005', WORKSPACE_A, 'ours'));
    $tenancy->run(WORKSPACE_B, fn () => insertWidget('01a0b000-0000-7000-8000-000000000006', WORKSPACE_B, 'theirs'));

    $seen = $tenancy->asStaff(fn (): int => DB::table('widgets')->count());

    expect($seen)->toBe(2);
});

it('applies the policy to the table owner too, or the application would be exempt', function (): void {
    expect(Invariants::tablesWithUnforcedPolicy())->not->toContain('widgets');
});

it('reports a tenant table nobody protected', function (): void {
    Schema::create('unguarded', function ($table): void {
        $table->uuid('id')->primary();
        $table->uuid('workspace_id')->nullable();
    });

    expect(Invariants::tablesWithoutPolicy())->toContain('unguarded');
});

it('leaves a table out of the report once the product says it is deliberate', function (): void {
    Schema::create('unguarded', function ($table): void {
        $table->uuid('id')->primary();
        $table->uuid('workspace_id')->nullable();
    });

    config(['platform.tenancy.unscoped_tables' => ['unguarded']]);

    expect(Invariants::tablesWithoutPolicy())->not->toContain('unguarded');
});

it('reads rows the workspace owns plus the platform wide ones', function (): void {
    Schema::create('templates', function ($table): void {
        $table->uuid('id')->primary();
        $table->uuid('workspace_id')->nullable();
        $table->string('name', 64);
    });

    Rls::protectAllowingGlobal('templates');

    app(Tenancy::class)->asPlatform(function (): void {
        DB::table('templates')->insert(['id' => '01a0b000-0000-7000-8000-000000000007', 'workspace_id' => null, 'name' => 'shipped']);
    });
})->throws(QueryException::class);

it('admits a platform wide write only through the sanctioned door', function (): void {
    Schema::create('templates', function ($table): void {
        $table->uuid('id')->primary();
        $table->uuid('workspace_id')->nullable();
        $table->string('name', 64);
    });

    Rls::protectAllowingGlobal('templates');
    Rls::allowPlatformWrite('templates');

    app(Tenancy::class)->asPlatform(function (): void {
        DB::table('templates')->insert(['id' => '01a0b000-0000-7000-8000-000000000008', 'workspace_id' => null, 'name' => 'shipped']);
    });

    $seen = app(Tenancy::class)->run(WORKSPACE_A, fn (): array => DB::table('templates')->pluck('name')->all());

    expect($seen)->toBe(['shipped']);
});

it('stops a written row ever being changed', function (): void {
    Rls::defineRejectMutation();

    Schema::create('trail', function ($table): void {
        $table->uuid('id')->primary();
        $table->uuid('workspace_id')->nullable();
        $table->string('note', 64);
    });

    Rls::protect('trail');
    Rls::appendOnly('trail');

    app(Tenancy::class)->run(WORKSPACE_A, function (): void {
        DB::table('trail')->insert(['id' => '01a0b000-0000-7000-8000-000000000009', 'workspace_id' => WORKSPACE_A, 'note' => 'written']);
        DB::table('trail')->where('note', 'written')->update(['note' => 'changed']);
    });
})->throws(QueryException::class, 'append-only');
