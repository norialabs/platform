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
        $this->artisan('platform:scheduler-healthy')->assertFailed();
    });

    it('passes once the heartbeat has run', function (): void {
        $this->artisan('platform:scheduler-heartbeat')->assertSuccessful();
        $this->artisan('platform:scheduler-healthy')->assertSuccessful();
    });

    it('fails again once the heartbeat has gone stale', function (): void {
        $this->artisan('platform:scheduler-heartbeat')->assertSuccessful();

        Cache::forget(SchedulerHealthyCommand::KEY);

        $this->artisan('platform:scheduler-healthy')->assertFailed();
    });

    it('holds the beat for longer than the minute it is scheduled at', function (): void {
        config(['platform.scheduler.heartbeat_ttl' => 300]);

        $this->artisan('platform:scheduler-heartbeat')->assertSuccessful();

        expect(Cache::get(SchedulerHealthyCommand::KEY))->not->toBeNull();
    });
});

describe('pruning sign-in codes', function (): void {
    it('removes the codes nobody will use again', function (): void {
        app(Otp::class)->issue(address());

        $this->travel(30)->days();

        $this->artisan('platform:prune-otp', ['--days' => 7])->assertSuccessful();

        expect(OtpChallenge::query()->count())->toBe(0);
    });

    it('leaves a code still worth keeping', function (): void {
        app(Otp::class)->issue(address());

        $this->artisan('platform:prune-otp')->assertSuccessful();

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
        $this->artisan('platform:tenancy-check')->assertSuccessful();
    });

    it('names a tenant table nobody protected', function (): void {
        DB::statement('create table unguarded (id uuid primary key, workspace_id uuid)');

        try {
            $this->artisan('platform:tenancy-check')
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
        config(['platform.db.disk' => 'backups']);

        $this->artisan('platform:restore', ['--force' => true])->assertFailed();
    });

    it('refuses a driver it has no dumper for', function (): void {
        config([
            'database.connections.oracle_probe' => ['driver' => 'oracle', 'database' => 'x'],
        ]);

        $this->artisan('platform:backup', ['--connection' => 'oracle_probe']);
    })->throws(RuntimeException::class, 'oracle');

    it('refuses to rebuild production unless told twice', function (): void {
        app()->detectEnvironment(fn (): string => 'production');

        $this->artisan('platform:rebuild')->assertFailed();
    });
});
