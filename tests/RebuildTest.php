<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use NoriaLabs\Platform\Db\Rebuild;
use NoriaLabs\Platform\Db\Schemas;

/*
 * The whole rebuild cycle takes a superuser, a copy database and a
 * migrate:fresh of the host's own migrations, which is a deployment
 * rehearsal rather than a unit test. What is tested here is every step
 * that can be exercised against one connection: the refusals that stop it
 * starting, the shape read, and the two operations that change a database.
 */
beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('A rebuild is Postgres only.');
    }
});

describe('refusing to start', function (): void {
    it('refuses a connection that is not postgres', function (): void {
        config([
            'database.connections.sqlite_probe' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'database.default' => 'sqlite_probe',
        ]);

        app(Rebuild::class)->run('copy_db', false, fn () => null);
    })->throws(RuntimeException::class, 'needs a pgsql connection');

    /*
     * A non-superuser cannot disable referential triggers for the reload,
     * and its dump comes back empty under row level security.
     */
    it('refuses a role that cannot carry the reload through', function (): void {
        app(Rebuild::class)->run('copy_db', false, fn () => null);
    })->throws(RuntimeException::class, 'not a superuser');
});

describe('reading the shape', function (): void {
    beforeEach(function (): void {
        Schema::create('widgets', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('name', 64);
            $table->text('note')->nullable();
        });
    });

    it('reads every column of every table it owns', function (): void {
        $shape = Schemas::shape(DB::connection());

        expect($shape)->toHaveKey('widgets');
        expect(array_keys($shape['widgets']))->toEqualCanonicalizing(['id', 'name', 'note']);
    });

    it('describes a column the way a drift comparison needs it', function (): void {
        $name = Schemas::shape(DB::connection())['widgets']['name'];

        expect($name->dataType)->toBe('character varying');
        expect($name->length)->toBe(64);
        expect($name->nullable)->toBeFalse();
    });

    it('leaves out the tables the product said not to carry', function (): void {
        expect(Schemas::shape(DB::connection(), ['widgets']))->not->toHaveKey('widgets');
    });

    it('knows which schemas belong to this connection', function (): void {
        expect(Schemas::owned(DB::connection()))->toContain('public');
    });

    it('builds a predicate a catalog query can be restricted by', function (): void {
        $filter = Schemas::filter('n.nspname', DB::connection());

        expect($filter['sql'])->toContain('n.nspname in (');
        expect($filter['bindings'])->toContain('public');
    });

    it('refuses to build one around a name it has not checked', function (): void {
        Schemas::filter('n.nspname; drop table widgets', DB::connection());
    })->throws(InvalidArgumentException::class);
});

describe('trimming a copy to the rebuilt shape', function (): void {
    beforeEach(function (): void {
        Schema::create('widgets', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('name', 64);
            $table->string('legacy', 64)->nullable();
        });

        Schema::create('gone', function ($table): void {
            $table->uuid('id')->primary();
        });
    });

    it('drops what the migrations no longer define', function (): void {
        app(Rebuild::class)->alignToRebuiltShape(
            DB::connection(),
            ['discardedTables' => ['gone'], 'discardedColumns' => [['table' => 'widgets', 'column' => 'legacy']]],
            fn () => null,
        );

        expect(Schema::hasTable('gone'))->toBeFalse();
        expect(Schema::hasColumn('widgets', 'legacy'))->toBeFalse();
        expect(Schema::hasColumn('widgets', 'name'))->toBeTrue();
    });

    it('does nothing at all when nothing drifted', function (): void {
        app(Rebuild::class)->alignToRebuiltShape(
            DB::connection(),
            ['discardedTables' => [], 'discardedColumns' => []],
            fn () => throw new RuntimeException('should not report'),
        );

        expect(Schema::hasTable('gone'))->toBeTrue();
    });

    it('refuses a table name it did not check', function (): void {
        app(Rebuild::class)->alignToRebuiltShape(
            DB::connection(),
            ['discardedTables' => ['widgets"; drop table gone; --'], 'discardedColumns' => []],
            fn () => null,
        );
    })->throws(InvalidArgumentException::class);
});

describe('orphaned routines', function (): void {
    /*
     * migrate:fresh drops tables, views and types and leaves routines
     * standing. One owned by a role the migrations no longer run as cannot
     * be recreated, and one deleted from the migrations would otherwise
     * survive every rebuild forever.
     */
    it('drops a function the migrations would otherwise never replace', function (): void {
        DB::unprepared('create or replace function leftover() returns int as $$ begin return 1; end; $$ language plpgsql');

        $dropped = app(Rebuild::class)->dropOrphanedRoutines(DB::connection(), fn () => null);

        expect($dropped)->toContain('leftover()');
        expect(DB::scalar("select count(*) from pg_proc where proname = 'leftover'"))->toBe(0);
    });

    it('reports nothing when there is nothing to drop', function (): void {
        app(Rebuild::class)->dropOrphanedRoutines(DB::connection(), fn () => null);

        expect(app(Rebuild::class)->dropOrphanedRoutines(DB::connection(), fn () => null))->toBe([]);
    });
});
