<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use NoriaLabs\Platform\Db\Rebuild;
use NoriaLabs\Platform\Db\Schemas;

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

    it('refuses a role that cannot carry the reload through, naming every missing privilege', function (): void {
        try {
            app(Rebuild::class)->run('copy_db', false, fn () => null);
        } catch (RuntimeException $refusal) {
            expect($refusal->getMessage())
                ->toContain('cannot carry the reload through')
                ->toContain('BYPASSRLS')
                ->toContain('CREATEDB')
                ->toContain('pg_signal_backend');

            return;
        }

        $this->fail('The rebuild started on a role that cannot finish it.');
    });
});

describe('holding the references off for a reload', function (): void {
    beforeEach(function (): void {
        DB::statement('create table reload_parents (id int primary key, name text not null)');
        DB::statement(<<<'SQL'
            create table reload_children (
                id int primary key,
                parent_id int not null references reload_parents (id),
                token text not null
            )
        SQL);
        DB::statement(<<<'SQL'
            create function reload_token() returns trigger language plpgsql as $$
            begin
                new.token := 'minted';
                return new;
            end $$
        SQL);
        DB::statement(
            'create trigger reload_children_token before insert on reload_children '
            .'for each row execute function reload_token()'
        );
    });

    afterEach(function (): void {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('drop table if exists reload_children');
        DB::statement('drop table if exists reload_parents');
        DB::statement('drop function if exists reload_token()');
    });

    it('takes rows in any order and puts the stored ones back untouched', function (): void {
        $put = app(Rebuild::class)->holdOffReferences(DB::connection(), function (): void {
            DB::statement("insert into reload_children values (1, 7, 'as stored')");
            DB::statement("insert into reload_parents values (7, 'late')");
        });

        expect($put)->toBe(1)
            ->and(DB::scalar('select token from reload_children where id = 1'))->toBe('as stored');
    });

    it('puts the foreign key back validated, so the load cannot smuggle an orphan through', function (): void {
        app(Rebuild::class)->holdOffReferences(DB::connection(), function (): void {
            DB::statement("insert into reload_parents values (7, 'first')");
            DB::statement("insert into reload_children values (1, 7, 'kept')");
        });

        $key = DB::selectOne(
            "select convalidated from pg_constraint where conname = 'reload_children_parent_id_fkey'"
        );

        expect($key?->convalidated)->toBeTrue()
            ->and(fn () => DB::transaction(
                fn () => DB::statement("insert into reload_children values (2, 404, 'orphan')")
            ))->toThrow(QueryException::class, 'reload_children_parent_id_fkey');
    });

    it('refuses a load that leaves an orphan behind', function (): void {
        expect(fn () => DB::transaction(fn () => app(Rebuild::class)->holdOffReferences(
            DB::connection(),
            fn () => DB::statement("insert into reload_children values (1, 404, 'orphan')"),
        )))->toThrow(QueryException::class, 'reload_children_parent_id_fkey');
    });

    it('lets the triggers fire again once the load is done', function (): void {
        app(Rebuild::class)->holdOffReferences(DB::connection(), function (): void {
            DB::statement("insert into reload_parents values (7, 'first')");
        });

        DB::statement("insert into reload_children values (1, 7, 'ignored')");

        expect(DB::scalar('select token from reload_children where id = 1'))->toBe('minted');
    });
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
