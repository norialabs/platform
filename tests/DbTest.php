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
use NoriaLabs\Platform\Db\Timestamps;

function asSuperuser(Closure $work): void
{
    $server = Connections::open('superuser-fixture', Connections::settings('noria_pg_superuser'));

    try {
        $work($server);
    } finally {
        $server->disconnect();
    }
}

function untrustedExtension(): ?string
{
    foreach (['vector', 'postgres_fdw', 'dblink', 'file_fdw'] as $name) {
        $found = committed()->scalar(
            'select 1 from pg_available_extension_versions where name = ? and superuser and not trusted and requires is null limit 1',
            [$name],
        );

        if ($found !== null) {
            return $name;
        }
    }

    return null;
}

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

function committed(): Connection
{
    return DB::connection('noria_pg_admin');
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
        config(['noria.db.disk' => 'backups']);
    });

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

    it('reports a failed sweep rather than losing the dump that succeeded', function (): void {
        config(['noria.db.tiers.hourly.prefix' => 'backups/hourly', 'noria.db.attempts' => 1]);
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

describe('sealing a dump', function (): void {
    beforeEach(function (): void {
        config(['noria.db.encryption_key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->plain = app(Backup::class)->workingDirectory().'/seal-'.bin2hex(random_bytes(3)).'.sql.gz';
        $this->body = random_bytes(2_500_000);
        file_put_contents($this->plain, $this->body);
    });

    afterEach(function (): void {
        foreach ([$this->plain, $this->plain.Backup::SEALED, $this->plain.'.out'] as $file) {
            @unlink($file);
        }
    });

    it('encrypts a dump larger than one chunk and opens it back byte for byte', function (): void {
        $sealed = app(Backup::class)->seal($this->plain);

        expect($sealed)->toEndWith(Backup::SEALED)
            ->and(str_contains((string) file_get_contents($sealed), substr($this->body, 0, 64)))->toBeFalse();

        app(Backup::class)->unseal($sealed, $this->plain.'.out');

        expect(file_get_contents($this->plain.'.out'))->toBe($this->body);
    });

    it('leaves a dump as it is when no key is configured', function (): void {
        config(['noria.db.encryption_key' => null]);

        expect(app(Backup::class)->seal($this->plain))->toBe($this->plain);
    });

    it('refuses a sealed dump that was altered or cut short', function (string $damage): void {
        $sealed = app(Backup::class)->seal($this->plain);
        $bytes = (string) file_get_contents($sealed);
        file_put_contents($sealed, $damage === 'altered' ? substr_replace($bytes, 'x', 5_000, 1) : substr($bytes, 0, -100));

        expect(fn () => app(Backup::class)->unseal($sealed, $this->plain.'.out'))
            ->toThrow(RuntimeException::class, 'tampered with');
    })->with(['altered', 'truncated']);

    it('refuses to open a dump with a different key', function (): void {
        $sealed = app(Backup::class)->seal($this->plain);
        config(['noria.db.encryption_key' => 'base64:'.base64_encode(random_bytes(32))]);

        expect(fn () => app(Backup::class)->unseal($sealed, $this->plain.'.out'))
            ->toThrow(RuntimeException::class, 'another key');
    });

    it('refuses a key that is not 32 bytes', function (): void {
        config(['noria.db.encryption_key' => 'base64:'.base64_encode('short')]);

        app(Backup::class)->seal($this->plain);
    })->throws(RuntimeException::class, 'must be 32 bytes');
});

describe('guarding an identifier', function (): void {
    it('accepts a plain name', function (): void {
        expect(Identifier::of('zana_copy_1'))->toBe('zana_copy_1');
    });

    it('refuses anything that would need quoting', function (string $name): void {
        Identifier::of($name);
    })->with(['drop table x', 'a"b', 'Mixed', '1leading', ''])->throws(InvalidArgumentException::class);

    it('quotes an extension name, hyphen and all', function (): void {
        expect(Identifier::quoted('uuid-ossp'))->toBe('"uuid-ossp"')
            ->and(Identifier::quoted('vector'))->toBe('"vector"');
    });

    it('refuses an extension name that could close the quote', function (string $name): void {
        Identifier::quoted($name);
    })->with(['a"b', 'drop table x', 'a;b', ''])->throws(InvalidArgumentException::class);
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
    beforeEach(function (): void {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('A dump needs a real server.');
        }

        if (env('NORIA_TEST_PG_ADMIN') === null) {
            $this->markTestSkipped('Needs a second connection whose role bypasses row level security.');
        }

        if (trim((string) shell_exec('command -v pg_dump')) === '') {
            $this->markTestSkipped('pg_dump is not on PATH.');
        }

        Storage::fake('backups');
        config(['noria.db.disk' => 'backups', 'noria.db.admin_connection' => 'noria_pg_admin']);

        committed()->statement('drop table if exists widgets');
        committed()->statement('create table widgets (id int primary key, name text)');
        committed()->table('widgets')->insert([['id' => 1, 'name' => 'ours'], ['id' => 2, 'name' => 'theirs']]);
        committed()->unprepared(
            'create or replace function widget_guard() returns trigger as $$ begin return new; end; $$ language plpgsql'
        );
    });

    afterEach(function (): void {
        if (env('NORIA_TEST_PG_ADMIN') !== null) {
            committed()->statement('drop table if exists widgets');
            committed()->statement('drop function if exists widget_guard() cascade');
        }
    });

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

    it('clears the functions a dump will recreate, so a second restore is not a collision', function (): void {
        $result = app(Backup::class)->run('backups');

        app(Restore::class)->run($result['key'], 'backups', 'platform_test_restore', force: true);
        $again = app(Restore::class)->run($result['key'], 'backups', 'platform_test_restore', force: true);

        expect($again['created'])->toBeFalse();

        $beside = Connections::open('assert', [
            ...Connections::asAdmin(Connections::settings()),
            'database' => 'platform_test_restore',
        ]);

        try {
            expect($beside->scalar("select count(*) from pg_proc where proname = 'widget_guard'"))->toBe(1)
                ->and($beside->table('widgets')->count())->toBe(2);
        } finally {
            $beside->disconnect();
            dropRestoreTarget();
        }
    });

    it('uploads an encrypted dump when a key is set, and restores it', function (): void {
        config(['noria.db.encryption_key' => 'base64:'.base64_encode(random_bytes(32))]);

        $result = app(Backup::class)->run('backups');

        expect($result['key'])->toEndWith('.sql.gz'.Backup::SEALED)
            ->and(@gzdecode((string) Storage::disk('backups')->get($result['key'])))->toBeFalse();

        $restored = app(Restore::class)->run(null, 'backups', 'platform_test_restore', force: true);

        $beside = Connections::open('assert', [
            ...Connections::asAdmin(Connections::settings()),
            'database' => 'platform_test_restore',
        ]);

        try {
            expect($restored['key'])->toBe($result['key'])
                ->and($beside->table('widgets')->count())->toBe(2);
        } finally {
            $beside->disconnect();
            dropRestoreTarget();
        }
    });

    it('leaves the database as it was when the dump it is loading fails partway', function (): void {
        $result = app(Backup::class)->run('backups');
        app(Restore::class)->run($result['key'], 'backups', 'platform_test_restore', force: true);

        $broken = 'backups/hourly/20990101-000000-aaaaaa-broken.sql.gz';
        Storage::disk('backups')->put($broken, (string) gzencode("create table half_loaded (id int);\nselect no_such_function();\n"));

        $beside = Connections::open('assert', [
            ...Connections::asAdmin(Connections::settings()),
            'database' => 'platform_test_restore',
        ]);

        try {
            expect(fn () => app(Restore::class)->run($broken, 'backups', 'platform_test_restore', force: true))
                ->toThrow(RuntimeException::class, 'psql failed');

            expect($beside->table('widgets')->count())->toBe(2)
                ->and($beside->scalar("select count(*) from pg_tables where tablename = 'half_loaded'"))->toBe(0);
        } finally {
            $beside->disconnect();
            dropRestoreTarget();
        }
    });

    it('promotes the first dump after the daily boundary', function (): void {
        config(['noria.db.tiers.daily.hour' => 0]);

        $result = app(Backup::class)->run('backups');

        expect($result['tier'])->toBe(BackupTier::Daily);
        expect($result['key'])->toStartWith(BackupTier::Daily->prefix());
    });

    it('keeps the rest of the day hourly once a daily one exists', function (): void {
        config(['noria.db.tiers.daily.hour' => 0]);

        app(Backup::class)->run('backups');
        $second = app(Backup::class)->run('backups');

        expect($second['tier'])->toBe(BackupTier::Hourly);
    });
});

describe('a dump that names an extension', function (): void {
    beforeEach(function (): void {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('A dump needs a real server.');
        }

        if (env('NORIA_TEST_PG_ADMIN') === null) {
            $this->markTestSkipped('Needs a second connection whose role bypasses row level security.');
        }

        if (env('NORIA_TEST_PG_SUPERUSER') === null) {
            $this->markTestSkipped('Needs a superuser connection to install an untrusted extension.');
        }

        if (trim((string) shell_exec('command -v pg_dump')) === '') {
            $this->markTestSkipped('pg_dump is not on PATH.');
        }

        $this->extension = untrustedExtension();

        if ($this->extension === null) {
            $this->markTestSkipped('This server has no untrusted extension to install.');
        }

        Storage::fake('backups');
        config([
            'noria.db.disk' => 'backups',
            'noria.db.admin_connection' => 'noria_pg_admin',
            'noria.db.superuser_connection' => 'noria_pg_superuser',
        ]);

        $extension = $this->extension;
        asSuperuser(fn (Connection $server) => $server->statement("create extension if not exists \"{$extension}\""));

        committed()->statement('drop table if exists widgets');
        committed()->statement('create table widgets (id int primary key, name text)');
        committed()->table('widgets')->insert([['id' => 1, 'name' => 'ours']]);
    });

    afterEach(function (): void {
        if (env('NORIA_TEST_PG_ADMIN') === null || env('NORIA_TEST_PG_SUPERUSER') === null) {
            return;
        }

        committed()->statement('drop table if exists widgets');

        if (is_string($this->extension ?? null)) {
            $extension = $this->extension;
            asSuperuser(fn (Connection $server) => $server->statement("drop extension if exists \"{$extension}\""));
        }

        dropRestoreTarget();
    });

    it('installs what the dump needs before reading it, and says what it installed', function (): void {
        $result = app(Backup::class)->run('backups');

        $restored = app(Restore::class)->run($result['key'], 'backups', 'platform_test_restore', force: true);

        expect($restored['extensions'])->toBe([$this->extension]);

        $beside = Connections::open('assert', [
            ...Connections::asAdmin(Connections::settings()),
            'database' => 'platform_test_restore',
        ]);

        try {
            expect($beside->scalar('select 1 from pg_extension where extname = ?', [$this->extension]))->toBe(1)
                ->and($beside->table('widgets')->pluck('name')->all())->toBe(['ours']);
        } finally {
            $beside->disconnect();
        }
    });

    it('leaves the extension alone on a second restore into the same database', function (): void {
        $result = app(Backup::class)->run('backups');

        app(Restore::class)->run($result['key'], 'backups', 'platform_test_restore', force: true);
        $again = app(Restore::class)->run($result['key'], 'backups', 'platform_test_restore', force: true);

        expect($again['extensions'])->toBe([])
            ->and($again['created'])->toBeFalse();
    });

    it('refuses rather than loading a dump it cannot equip the target for', function (): void {
        config(['noria.db.superuser_connection' => null]);

        $result = app(Backup::class)->run('backups');

        expect(fn () => app(Restore::class)->run($result['key'], 'backups', 'platform_test_restore', force: true))
            ->toThrow(RuntimeException::class, 'create extension if not exists "'.$this->extension.'";');

        $beside = Connections::open('assert', [
            ...Connections::asAdmin(Connections::settings()),
            'database' => 'platform_test_restore',
        ]);

        try {
            expect($beside->scalar("select count(*) from pg_tables where schemaname = 'public'"))->toBe(0);
        } finally {
            $beside->disconnect();
        }
    });
});

describe('the preflight', function (): void {
    beforeEach(function (): void {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('A dump needs a real server.');
        }

        Storage::fake('backups');
        config(['noria.db.disk' => 'backups', 'noria.db.admin_connection' => null]);
    });

    it('refuses to dump as a role that would produce an empty file', function (): void {
        app(Backup::class)->run('backups');
    })->throws(RuntimeException::class, 'bypass row level security');
});

describe('timezone aware timestamps', function (): void {
    beforeEach(function (): void {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The mismatch only exists on Postgres.');
        }
    });

    it('refuses a connection whose timezone is not the application one', function (): void {
        DB::statement("set time zone 'Africa/Nairobi'");

        try {
            Timestamps::assertAligned();
        } finally {
            DB::statement("set time zone 'UTC'");
        }
    })->throws(RuntimeException::class, 'wrong moment');

    it('accepts a connection that agrees with the application', function (): void {
        Timestamps::assertAligned();

        expect(true)->toBeTrue();
    });

    it('treats UTC and its spellings as one timezone', function (): void {
        DB::statement("set time zone 'Etc/UTC'");

        try {
            Timestamps::assertAligned();
            expect(true)->toBeTrue();
        } finally {
            DB::statement("set time zone 'UTC'");
        }
    });

    it('has nothing to check when the product asked for plain timestamps', function (): void {
        config(['noria.timestamps' => 'plain']);
        DB::statement("set time zone 'Africa/Nairobi'");

        try {
            Timestamps::assertAligned();
            expect(Timestamps::aware())->toBeFalse();
        } finally {
            DB::statement("set time zone 'UTC'");
        }
    });

    it('writes the columns timezone aware by default', function (): void {
        $type = DB::scalar(
            "select data_type from information_schema.columns where table_name = ? and column_name = 'expires_at'",
            ['invitations'],
        );

        expect($type)->toBe('timestamp with time zone');
    });
});
