<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use NoriaLabs\Platform\Audit\AuditLog;
use NoriaLabs\Platform\Auth\OtpChallenge;
use NoriaLabs\Platform\Db\Backup;
use NoriaLabs\Platform\Db\DumperFactory;
use NoriaLabs\Platform\Db\Dumpers\MysqlDumper;
use NoriaLabs\Platform\Db\Dumpers\PostgresDumper;
use NoriaLabs\Platform\Db\Dumpers\SqliteDumper;
use NoriaLabs\Platform\Http\Middleware\SecurityHeaders;
use NoriaLabs\Platform\Platform;
use Symfony\Component\HttpFoundation\Response;

describe('naming the tables', function (): void {
    it('creates the tables under the names the models will look for', function (): void {
        foreach (['audit_logs', 'otp_challenges'] as $name) {
            expect(Schema::hasTable(Platform::table($name)))->toBeTrue("missing {$name}");
        }
    });

    it('prefixes every table so a host can keep them out of its own namespace', function (): void {
        config(['platform.table_prefix' => 'noria_']);

        expect((new AuditLog)->getTable())->toBe('noria_audit_logs');
        expect((new OtpChallenge)->getTable())->toBe('noria_otp_challenges');
    });

    it('renames one table without spelling out the others', function (): void {
        config(['platform.tables.audit_logs' => 'activity']);

        expect((new AuditLog)->getTable())->toBe('activity');
        expect((new OtpChallenge)->getTable())->toBe('otp_challenges');
    });

    it('keys every table on a uuid rather than an auto-increment', function (): void {
        foreach (['audit_logs', 'otp_challenges'] as $name) {
            expect(Schema::getColumnType(Platform::table($name), 'id'))->not->toBe('integer');
        }
    });

    it('generates version 7 identifiers, so rows sort by when they were written', function (): void {
        // The version nibble is the first character of the third group.
        expect(explode('-', (new AuditLog)->newUniqueId())[2][0])->toBe('7');
    });
});

describe('security headers', function (): void {
    function respond(?Response $response = null): Response
    {
        return (new SecurityHeaders)->handle(Request::create('/'), fn () => $response ?? new Response);
    }

    it('sends the headers every response should carry', function (): void {
        $headers = respond()->headers;

        expect($headers->get('X-Content-Type-Options'))->toBe('nosniff');
        expect($headers->get('X-Frame-Options'))->toBe('DENY');
        expect($headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
        expect($headers->get('Content-Security-Policy'))->toContain("default-src 'self'");
    });

    it('lets a product relax one directive without dropping the middleware', function (): void {
        config(['platform.http.security_headers.directives.script-src' => ["'self'", 'https://cdn.example.com']]);

        expect(respond()->headers->get('Content-Security-Policy'))->toContain('https://cdn.example.com');
    });

    it('reports rather than enforces while a product is still finding its directives', function (): void {
        config(['platform.http.security_headers.report_only' => true]);

        expect(respond()->headers->get('Content-Security-Policy-Report-Only'))->not->toBeNull();
        expect(respond()->headers->get('Content-Security-Policy'))->toBeNull();
    });

    /* A PDF the browser renders in a frame cannot be served frame-ancestors none. */
    it('lets a pdf be framed by the page that opened it', function (): void {
        $pdf = new Response('', 200, ['Content-Type' => 'application/pdf']);

        expect(respond($pdf)->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'");
        expect(respond($pdf)->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
    });

    it('sends nothing at all when a product turns it off', function (): void {
        config(['platform.http.security_headers.enabled' => false]);

        expect(respond()->headers->get('Content-Security-Policy'))->toBeNull();
    });

    it('holds transport security back until the request is actually secure', function (): void {
        expect(respond()->headers->get('Strict-Transport-Security'))->toBeNull();
    });
});

describe('database operations', function (): void {
    it('picks a dumper for each driver the estate runs', function (): void {
        $factory = app(DumperFactory::class);

        expect($factory->make('pgsql'))->toBeInstanceOf(PostgresDumper::class);
        expect($factory->make('mysql'))->toBeInstanceOf(MysqlDumper::class);
        expect($factory->make('mariadb'))->toBeInstanceOf(MysqlDumper::class);
        expect($factory->make('sqlite'))->toBeInstanceOf(SqliteDumper::class);
    });

    it('says which driver it has no dumper for', function (): void {
        app(DumperFactory::class)->make('oracle');
    })->throws(RuntimeException::class, 'oracle');

    it('lets a product register a dumper of its own', function (): void {
        $factory = app(DumperFactory::class);
        $factory->register('oracle', SqliteDumper::class);

        expect($factory->make('oracle'))->toBeInstanceOf(SqliteDumper::class);
    });

    it('keeps only as many backups as the product asked for', function (): void {
        Storage::fake('backups');
        config(['platform.db.disk' => 'backups', 'platform.db.keep' => 2]);

        foreach (['a', 'b', 'c', 'd'] as $name) {
            Storage::disk('backups')->put("backups/db-{$name}.sql.gz", 'x');
        }

        $removed = app(Backup::class)->prune();

        expect($removed)->toHaveCount(2);
        expect(app(Backup::class)->all())->toBe(['backups/db-d.sql.gz', 'backups/db-c.sql.gz']);
    });

    it('keeps everything when the product set no limit', function (): void {
        Storage::fake('backups');
        config(['platform.db.disk' => 'backups', 'platform.db.keep' => 0]);

        Storage::disk('backups')->put('backups/db-a.sql.gz', 'x');

        expect(app(Backup::class)->prune())->toBe([]);
    });
});
