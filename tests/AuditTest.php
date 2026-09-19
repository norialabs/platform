<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use NoriaLabs\Platform\Audit\AuditLog;
use NoriaLabs\Platform\Audit\AuditRecorder;
use NoriaLabs\Platform\Audit\RequestContext;
use NoriaLabs\Platform\Platform;
use NoriaLabs\Platform\Tests\Fixtures\HostAuditLog;

it('writes down what happened and who did it', function (): void {
    app(AuditRecorder::class)->record('invoice.voided', 'invoice', 'inv-1', ['amount' => 500], 'duplicate');

    $entry = AuditLog::query()->sole();

    expect($entry->action)->toBe('invoice.voided');
    expect($entry->target_type)->toBe('invoice');
    expect($entry->target_id)->toBe('inv-1');
    expect($entry->reason)->toBe('duplicate');
    expect($entry->metadata)->toBe(['amount' => 500]);
});

it('records the scheduler as the system rather than as nobody', function (): void {
    app(AuditRecorder::class)->record('backup.taken');

    expect(AuditLog::query()->sole()->actor_type)->toBe('system');
});

it('cannot be edited once it is written', function (): void {
    app(AuditRecorder::class)->record('deal.won');

    AuditLog::query()->sole()->update(['action' => 'deal.lost']);
})->throws(RuntimeException::class, 'append-only');

it('cannot be deleted once it is written', function (): void {
    app(AuditRecorder::class)->record('deal.won');

    AuditLog::query()->sole()->delete();
})->throws(RuntimeException::class, 'append-only');

it('does nothing at all when the product turned the trail off', function (): void {
    config(['noria.audit.enabled' => false]);

    expect(app(AuditRecorder::class)->record('deal.won'))->toBeNull();
    expect(AuditLog::query()->count())->toBe(0);
});

it('writes through the model the host substituted', function (): void {
    Platform::useAuditLogModel(HostAuditLog::class);

    app(AuditRecorder::class)->record('deal.won');

    expect(HostAuditLog::query()->sole())->toBeInstanceOf(HostAuditLog::class);
});

it('refuses a substitute that is not an audit log', function (): void {
    Platform::useAuditLogModel(stdClass::class);
})->throws(InvalidArgumentException::class);

it('ties every row of one request together with a shared id', function (): void {
    $recorder = app(AuditRecorder::class);

    $recorder->record('one');
    $recorder->record('two');

    expect(AuditLog::query()->distinct()->pluck('request_id'))->toHaveCount(1);
});

it('takes the request id the edge assigned, so logs and rows join up', function (): void {
    $request = Request::create('/');
    $request->headers->set('X-Request-Id', 'edge-123');

    expect((new RequestContext($request))->id())->toBe('edge-123');
});

it('trims a user agent long enough to overflow its column', function (): void {
    $request = Request::create('/');
    $request->headers->set('User-Agent', str_repeat('x', 900));

    expect(mb_strlen((string) (new RequestContext($request))->userAgent()))->toBe(512);
});

/*
 * The package cannot know the host's user model. Keyed as a uuid, a product
 * still on bigint users had every write refused with "invalid input syntax
 * for type uuid".
 */
it('records an actor from a product whose users are not keyed on uuid', function (): void {
    Auth::shouldReceive('id')->andReturn(42);

    app(AuditRecorder::class)->record('invoice.voided');

    expect(AuditLog::query()->sole()->actor_id)->toBe('42');
});
