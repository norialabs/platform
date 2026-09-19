<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use NoriaLabs\Platform\Db\Backup;
use NoriaLabs\Platform\Db\BackupTier;
use NoriaLabs\Platform\Db\ColumnShape;
use NoriaLabs\Platform\Db\Connections;
use NoriaLabs\Platform\Db\DumperFactory;
use NoriaLabs\Platform\Db\Dumpers\MysqlDumper;
use NoriaLabs\Platform\Db\Dumpers\PostgresDumper;
use NoriaLabs\Platform\Db\Dumpers\SqliteDumper;
use NoriaLabs\Platform\Db\Identifier;
use NoriaLabs\Platform\Db\Restore;
use NoriaLabs\Platform\Db\Schemas;

function dropRestoreTarget(): void
{
    $admin = Connections::open('teardown', [
        ...Connections::asAdmin(Connections::settings()),
        'database' => 'postgres',
    ]);

    try {
        $admin->statement('drop database if exists "platform_test_restore" with (force)');
    } finally {
        $admin->disconnect();
    }
}

/** A connection outside the test transaction, so a separate process can see the writes. */
function committed(): Connection
{
    return DB::connection('platform_pg_admin');
}

function dumpKey(string $tier, string $stamp, string $name = 'db'): string
{
    return BackupTier::from($tier)->prefix()."/{$stamp}-abc123-{$name}.sql.gz";
}

describe('choosing a dumper', function (): void {
    it('has one for every driver the estate runs', function (): void {
        $factory = app(DumperFactory::class);

        expect($factory->make('pgsql'))->toBeInstanceOf(PostgresDumper::class);
        expect($factory->make('mysql'))->toBeInstanceOf(MysqlDumper::class);
        expect($factory->make('mariadb'))->toBeInstanceOf(MysqlDumper::class);
        expect($factory->make('sqlite'))->toBeInstanceOf(SqliteDumper::class);
    });

    it('says which driver it has none for', function (): void {
        app(DumperFactory::class)->make('oracle');
    })->throws(RuntimeException::class, 'oracle');

    it('lets a product register one of its own', function (): void {
        $factory = app(DumperFactory::class);
        $factory->register('oracle', SqliteDumper::class);

        expect($factory->make('oracle'))->toBeInstanceOf(SqliteDumper::class);
    });
});

describe('tiering and retention', function (): void {
    beforeEach(function (): void {
        Storage::fake('backups');
        config(['platform.db.disk' => 'backups']);
    });

    /*
     * An hourly dump answers this morning's mistake; a daily one answers
     * the corruption nobody noticed for a fortnight. Keeping a fortnight of
     * hourlies to get the second costs fourteen times the storage.
     */
    it('keeps each tier for its own window', function (): void {
        $now = Carbon::now('UTC');
        $disk = Storage::disk('backups');

        $disk->put(dumpKey('hourly', $now->copy()->subHours(10)->format('Ymd-His')), 'x');
        $disk->put(dumpKey('hourly', $now->copy()->subHours(60)->format('Ymd-His')), 'x');
        $disk->put(dumpKey('daily', $now->copy()->subDays(10)->format('Ymd-His')), 'x');
        $disk->put(dumpKey('daily', $now->copy()->subDays(40)->format('Ymd-His')), 'x');

        $backups = app(Backup::class);

        expect($backups->prune('backups', BackupTier::Hourly))->toBe(1);
        expect($backups->prune('backups', BackupTier::Daily))->toBe(1);
        expect($backups->all('backups'))->toHaveCount(2);
    });

    it('leaves a file it cannot date alone rather than deleting it', function (): void {
        Storage::disk('backups')->put(BackupTier::Hourly->prefix().'/notes.txt', 'x');

        expect(app(Backup::class)->prune('backups', BackupTier::Hourly))->toBe(0);
    });

    /*
     * The dump is already safe. Failing the run because the sweep failed
     * would turn a storage bill into a missing backup.
     */
    it('reports a failed sweep rather than losing the dump that succeeded', function (): void {
        config(['platform.db.tiers.hourly.prefix' => 'backups/hourly', 'platform.db.attempts' => 1]);
        Storage::shouldReceive('disk')->andThrow(new RuntimeException('the endpoint is gone'));

        expect(app(Backup::class)->prune('backups', BackupTier::Hourly))->toBe(0);
    });

    it('finds the newest dump across both tiers', function (): void {
        $disk = Storage::disk('backups');
        $disk->put(dumpKey('hourly', '20260101-010000'), 'x');
        $disk->put(dumpKey('daily', '20260101-020000'), 'x');
        $disk->put(dumpKey('hourly', '20260101-000000'), 'x');

        expect(app(Backup::class)->latestKey('backups'))->toContain('20260101-020000');
    });

    it('says so plainly when there is nothing to restore', function (): void {
        app(Backup::class)->latestKey('backups');
    })->throws(RuntimeException::class, 'no dump to restore');

    it('reads a timestamp back out of a name it wrote', function (): void {
        expect(Backup::takenAt('backups/hourly/20260315-142530-abc123-db.sql.gz')?->format('Y-m-d H:i:s'))
            ->toBe('2026-03-15 14:25:30');
        expect(Backup::takenAt('backups/hourly/notes.txt'))->toBeNull();
    });
});

describe('guarding an identifier', function (): void {
    /* A name on its way into DDL cannot be parameterised. */
    it('accepts a plain name', function (): void {
        expect(Identifier::of('zana_copy_1'))->toBe('zana_copy_1');
    });

    it('refuses anything that would need quoting', function (string $name): void {
        Identifier::of($name);
    })->with(['drop table x', 'a"b', 'Mixed', '1leading', ''])->throws(InvalidArgumentException::class);
});

describe('comparing two schemas', function (): void {
    function shapeOf(string $table, string $name, string $type = 'text', int $len = -1, bool $null = true, ?string $default = null): ColumnShape
    {
        return new ColumnShape($table, $name, $type, $len, $null, $default);
    }

    it('carries a table both shapes define', function (): void {
        $shape = ['invoices' => ['id' => shapeOf('invoices', 'id')]];

        $drift = Schemas::drift($shape, $shape, fn (): int => 5);

        expect($drift['blocking'])->toBe([]);
        expect($drift['carried'])->toBe(1);
    });

    it('discards a table the migrations dropped when it is empty', function (): void {
        $drift = Schemas::drift(
            ['legacy' => ['id' => shapeOf('legacy', 'id')]],
            [],
            fn (): int => 0,
        );

        expect($drift['discardedTables'])->toBe(['legacy']);
        expect($drift['blocking'])->toBe([]);
    });

    /* The whole point: no row is lost quietly. */
    it('refuses to drop a table that still holds rows', function (): void {
        $drift = Schemas::drift(
            ['legacy' => ['id' => shapeOf('legacy', 'id')]],
            [],
            fn (): int => 12,
        );

        expect($drift['blocking'][0])->toContain('legacy still holds 12 rows');
    });

    it('refuses to drop a column that still holds values', function (): void {
        $drift = Schemas::drift(
            ['invoices' => ['id' => shapeOf('invoices', 'id'), 'old' => shapeOf('invoices', 'old')]],
            ['invoices' => ['id' => shapeOf('invoices', 'id')]],
            fn (string $table, ?string $column): int => $column === null ? 3 : 3,
        );

        expect($drift['blocking'][0])->toContain('invoices.old still holds 3 values');
    });

    it('refuses a type change under existing rows', function (): void {
        $drift = Schemas::drift(
            ['invoices' => ['total' => shapeOf('invoices', 'total', 'text')]],
            ['invoices' => ['total' => shapeOf('invoices', 'total', 'integer')]],
            fn (): int => 4,
        );

        expect($drift['blocking'][0])->toContain('changes from text to integer');
    });

    it('refuses a column that narrows under existing rows', function (): void {
        $drift = Schemas::drift(
            ['invoices' => ['ref' => shapeOf('invoices', 'ref', 'character varying', 255)]],
            ['invoices' => ['ref' => shapeOf('invoices', 'ref', 'character varying', 64)]],
            fn (): int => 4,
        );

        expect($drift['blocking'][0])->toContain('narrows from');
    });

    it('refuses a new not-null column with no default under existing rows', function (): void {
        $drift = Schemas::drift(
            ['invoices' => ['id' => shapeOf('invoices', 'id')]],
            ['invoices' => ['id' => shapeOf('invoices', 'id'), 'status' => shapeOf('invoices', 'status', null: false)]],
            fn (): int => 4,
        );

        expect($drift['blocking'][0])->toContain('status is new, not null and has no default');
    });

    /* An empty table can change shape however it likes. */
    it('allows any change to a table holding nothing', function (): void {
        $drift = Schemas::drift(
            ['invoices' => ['total' => shapeOf('invoices', 'total', 'text')]],
            ['invoices' => ['total' => shapeOf('invoices', 'total', 'integer'), 'new' => shapeOf('invoices', 'new', null: false)]],
            fn (): int => 0,
        );

        expect($drift['blocking'])->toBe([]);
    });

    it('counts what the migrations added', function (): void {
        $drift = Schemas::drift(
            ['invoices' => ['id' => shapeOf('invoices', 'id')]],
            [
                'invoices' => ['id' => shapeOf('invoices', 'id'), 'note' => shapeOf('invoices', 'note')],
                'receipts' => ['id' => shapeOf('receipts', 'id')],
            ],
            fn (): int => 1,
        );

        expect($drift['additions'])->toBe(1);
        expect($drift['newTables'])->toBe(1);
    });
});

describe('a real dump and restore', function (): void {
    /*
     * Everything here runs on a second connection rather than the default
     * one. RefreshDatabase holds the test inside a transaction, and pg_dump
     * is a separate process: rows written on the default connection are
     * uncommitted and the dump would come back without them.
     */
    beforeEach(function (): void {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('A dump needs a real server.');
        }

        if (env('PLATFORM_TEST_PG_ADMIN') === null) {
            $this->markTestSkipped('Needs a second connection whose role bypasses row level security.');
        }

        if (trim((string) shell_exec('command -v pg_dump')) === '') {
            $this->markTestSkipped('pg_dump is not on PATH.');
        }

        Storage::fake('backups');
        config(['platform.db.disk' => 'backups', 'platform.db.admin_connection' => 'platform_pg_admin']);

        committed()->statement('drop table if exists widgets');
        committed()->statement('create table widgets (id int primary key, name text)');
        committed()->table('widgets')->insert([['id' => 1, 'name' => 'ours'], ['id' => 2, 'name' => 'theirs']]);
    });

    afterEach(function (): void {
        if (env('PLATFORM_TEST_PG_ADMIN') !== null) {
            committed()->statement('drop table if exists widgets');
        }
    });

    /*
     * Restored beside the live database rather than over it. A restore
     * replays the dump as the role running it, so every table comes back
     * owned by that role - doing it in place would take the application
     * role's access to its own tables away.
     */
    it('writes a dump that holds the rows, and puts them back', function (): void {
        $result = app(Backup::class)->run('backups');

        expect($result['bytes'])->toBeGreaterThan(0);
        expect(Storage::disk('backups')->exists($result['key']))->toBeTrue();

        $restored = app(Restore::class)->run($result['key'], 'backups', 'platform_test_restore', force: true);

        expect($restored['created'])->toBeTrue();
        expect($restored['database'])->toBe('platform_test_restore');

        $beside = Connections::open('assert', [
            ...Connections::asAdmin(Connections::settings()),
            'database' => 'platform_test_restore',
        ]);

        try {
            expect($beside->table('widgets')->pluck('name')->sort()->values()->all())
                ->toBe(['ours', 'theirs']);
        } finally {
            $beside->disconnect();
            dropRestoreTarget();
        }
    });

    it('promotes the first dump after the daily boundary', function (): void {
        config(['platform.db.tiers.daily.hour' => 0]);

        $result = app(Backup::class)->run('backups');

        expect($result['tier'])->toBe(BackupTier::Daily);
        expect($result['key'])->toStartWith(BackupTier::Daily->prefix());
    });

    it('keeps the rest of the day hourly once a daily one exists', function (): void {
        config(['platform.db.tiers.daily.hour' => 0]);

        app(Backup::class)->run('backups');
        $second = app(Backup::class)->run('backups');

        expect($second['tier'])->toBe(BackupTier::Hourly);
    });
});

describe('the preflight', function (): void {
    beforeEach(function (): void {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('A dump needs a real server.');
        }

        Storage::fake('backups');
        config(['platform.db.disk' => 'backups', 'platform.db.admin_connection' => null]);
    });

    /*
     * The failure this check exists for: pg_dump as a role that cannot
     * bypass row level security writes a file that looks entirely normal
     * and contains no rows at all.
     */
    it('refuses to dump as a role that would produce an empty file', function (): void {
        app(Backup::class)->run('backups');
    })->throws(RuntimeException::class, 'bypass row level security');
});
