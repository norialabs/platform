<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use NoriaLabs\Platform\Auth\Otp;
use NoriaLabs\Platform\Auth\OtpChallenge;
use NoriaLabs\Platform\Console\SchedulerHealthyCommand;
use NoriaLabs\Platform\Identity\Destination;

function address(): Destination
{
    return Destination::tryFrom('ada@example.com') ?? throw new RuntimeException('bad fixture');
}

describe('the scheduler healthcheck', function (): void {
    /*
     * A scheduler running but never firing looks identical to a healthy
     * one from outside, which is the whole reason for the heartbeat.
     */
    it('fails while the scheduler has never checked in', function (): void {
        $this->artisan('noria:scheduler-healthy')->assertFailed();
    });

    it('passes once the heartbeat has run', function (): void {
        $this->artisan('noria:scheduler-heartbeat')->assertSuccessful();
        $this->artisan('noria:scheduler-healthy')->assertSuccessful();
    });

    it('fails again once the heartbeat has gone stale', function (): void {
        $this->artisan('noria:scheduler-heartbeat')->assertSuccessful();

        Cache::forget(SchedulerHealthyCommand::KEY);

        $this->artisan('noria:scheduler-healthy')->assertFailed();
    });

    it('holds the beat for longer than the minute it is scheduled at', function (): void {
        config(['noria.scheduler.heartbeat_ttl' => 300]);

        $this->artisan('noria:scheduler-heartbeat')->assertSuccessful();

        expect(Cache::get(SchedulerHealthyCommand::KEY))->not->toBeNull();
    });
});

describe('pruning sign-in codes', function (): void {
    it('removes the codes nobody will use again', function (): void {
        app(Otp::class)->issue(address());

        $this->travel(30)->days();

        $this->artisan('noria:prune-otp', ['--days' => 7])->assertSuccessful();

        expect(OtpChallenge::query()->count())->toBe(0);
    });

    it('leaves a code still worth keeping', function (): void {
        app(Otp::class)->issue(address());

        $this->artisan('noria:prune-otp')->assertSuccessful();

        expect(OtpChallenge::query()->count())->toBe(1);
    });
});

describe('the tenancy check', function (): void {
    beforeEach(function (): void {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The invariants are Postgres catalog queries.');
        }
    });

    /*
     * A deployment check, not a request check. It passes here because the
     * suite deliberately runs as a role that cannot bypass a policy.
     */
    it('passes on a database whose role cannot step around a policy', function (): void {
        $this->artisan('noria:tenancy-check')->assertSuccessful();
    });

    it('names a tenant table nobody protected', function (): void {
        DB::statement('create table unguarded (id uuid primary key, workspace_id uuid)');

        try {
            $this->artisan('noria:tenancy-check')
                ->expectsOutputToContain('unguarded')
                ->assertFailed();
        } finally {
            DB::statement('drop table if exists unguarded');
        }
    });
});

describe('the backup commands', function (): void {
    it('says there is nothing to restore rather than failing obscurely', function (): void {
        Storage::fake('backups');
        config(['noria.db.disk' => 'backups']);

        $this->artisan('noria:restore', ['--force' => true])->assertFailed();
    });

    it('refuses a driver it has no dumper for', function (): void {
        config([
            'database.connections.oracle_probe' => ['driver' => 'oracle', 'database' => 'x'],
        ]);

        $this->artisan('noria:backup', ['--connection' => 'oracle_probe']);
    })->throws(RuntimeException::class, 'oracle');

    it('refuses to rebuild production unless told twice', function (): void {
        app()->detectEnvironment(fn (): string => 'production');

        $this->artisan('noria:rebuild')->assertFailed();
    });
});

describe('the static error pages', function (): void {
    beforeEach(function (): void {
        config(['noria.errors.stylesheet' => null, 'noria.errors.codes' => [502]]);
    });

    afterEach(function (): void {
        foreach ([502, 504] as $code) {
            if (is_file(public_path("{$code}.html"))) {
                unlink(public_path("{$code}.html"));
            }
        }
    });

    /*
     * A 502 means the application is not answering, so the page for it
     * cannot be rendered by the application when it is needed.
     */
    it('writes a file the edge can serve without the application', function (): void {
        app('view')->addNamespace('errors', __DIR__.'/Fixtures/views');
        config(['noria.errors.view' => 'errors::']);

        $this->artisan('noria:build-error-pages')->assertSuccessful();

        expect(file_get_contents(public_path('502.html')))->toContain('Bad gateway');
    });

    it('says which view is missing rather than writing an empty page', function (): void {
        config(['noria.errors.view' => 'nowhere.']);

        $this->artisan('noria:build-error-pages')->assertFailed();

        expect(is_file(public_path('502.html')))->toBeFalse();
    });

    it('refuses when the stylesheet it would inline is not built', function (): void {
        config(['noria.errors.stylesheet' => 'resources/css/nothing.css']);

        $this->artisan('noria:build-error-pages')->assertFailed();
    });

    it('does nothing when a product configured no pages', function (): void {
        config(['noria.errors.codes' => []]);

        $this->artisan('noria:build-error-pages')->assertSuccessful();
    });
});

describe('the deployment check and the clock', function (): void {
    beforeEach(function (): void {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The mismatch only exists on Postgres.');
        }
    });

    /*
     * Checked here as well as at migrate time: a connection added later,
     * or a config edit, would otherwise go unnoticed until the next
     * migration, by which point the rows are already wrong.
     */
    it('fails when the connection would store every moment at the wrong instant', function (): void {
        DB::statement("set time zone 'Africa/Nairobi'");

        try {
            $this->artisan('noria:tenancy-check')
                ->expectsOutputToContain('wrong moment')
                ->assertFailed();
        } finally {
            DB::statement("set time zone 'UTC'");
        }
    });

    it('passes when the clock and the policies both agree', function (): void {
        $this->artisan('noria:tenancy-check')->assertSuccessful();
    });

    it('says nothing about the clock when the product asked for plain timestamps', function (): void {
        config(['noria.timestamps' => 'plain']);
        DB::statement("set time zone 'Africa/Nairobi'");

        try {
            $this->artisan('noria:tenancy-check')->assertSuccessful();
        } finally {
            DB::statement("set time zone 'UTC'");
        }
    });
});
