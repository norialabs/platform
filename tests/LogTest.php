<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use NoriaLabs\Platform\Contracts\LogContext;
use NoriaLabs\Platform\Log\Logger;
use NoriaLabs\Platform\Log\Redactor;
use NoriaLabs\Platform\Tests\Fixtures\StubLogContext;

describe('redacting a payload', function (): void {
    it('masks a password rather than printing it', function (): void {
        expect(Redactor::scrub(['password' => 'hunter2secret']))
            ->toBe(['password' => 'hu*********et']);
    });

    it('leaves enough of a token to answer a support ticket about it', function (): void {
        expect(Redactor::scrub(['token' => 'abcdef123456'])['token'])
            ->toBe('ab********56');
    });

    it('hides a short value completely rather than almost completely', function (): void {
        expect(Redactor::scrub(['pin' => '1234'])['pin'])->toBe('****');
    });

    /* Api-Key, api_key and apikey are one key to everybody except a string comparison. */
    it('treats a key as the same key however it was punctuated', function (): void {
        foreach (['Api-Key', 'api_key', 'apikey', 'API KEY'] as $key) {
            expect(Redactor::sensitive($key))->toBeTrue($key);
        }
    });

    it('catches a prefixed credential an exact list would miss', function (): void {
        expect(Redactor::sensitive('merchant_api_key'))->toBeTrue();
        expect(Redactor::sensitive('daraja_consumer_secret'))->toBeTrue();
    });

    it('masks personal data, not only credentials', function (): void {
        $scrubbed = Redactor::scrub(['email' => 'ada@example.com', 'msisdn' => '254712345678']);

        expect($scrubbed['email'])->not->toContain('ada@example.com');
        expect($scrubbed['msisdn'])->not->toContain('712345678');
    });

    it('reaches a credential nested inside a payload', function (): void {
        $scrubbed = Redactor::scrub(['request' => ['headers' => ['authorization' => 'Bearer abcdef123456']]]);

        expect($scrubbed['request']['headers']['authorization'])->not->toContain('abcdef');
    });

    it('leaves everything that is not sensitive exactly as it was', function (): void {
        expect(Redactor::scrub(['invoice_id' => 'inv-1', 'amount' => 500]))
            ->toBe(['invoice_id' => 'inv-1', 'amount' => 500]);
    });

    it('redacts an object it cannot mask character by character', function (): void {
        expect(Redactor::scrub(['secret' => new stdClass])['secret'])->toBe('[redacted]');
    });

    /* A field sensitive in one product is sensitive everywhere the log ends up. */
    it('adds what a product declares, however the product spelled it', function (): void {
        config(['noria.log.pii_keys' => ['kraPin']]);

        expect(Redactor::sensitive('kra_pin'))->toBeTrue();
        expect(Redactor::sensitive('krapin'))->toBeTrue();
        expect(Redactor::sensitive('password'))->toBeTrue();
    });
});

describe('writing a log line', function (): void {
    it('scrubs the context before it reaches the log', function (): void {
        Log::shouldReceive('log')->once()->withArgs(
            fn (string $level, string $message, array $context): bool => ! str_contains((string) $context['password'], 'hunter2')
        );

        Logger::app('signed in', ['password' => 'hunter2secret']);
    });

    /*
     * Asking the log manager for a channel that is not defined throws, which
     * it catches by writing an EMERGENCY entry alongside the real one.
     */
    it('falls back to the default channel rather than logging the same thing twice', function (): void {
        Log::shouldReceive('log')->once();
        Log::shouldReceive('channel')->never();

        Logger::auth('otp issued');
    });

    it('uses the product channel once the product defines one', function (): void {
        config(['logging.channels.auth' => ['driver' => 'null']]);

        Log::shouldReceive('channel')->once()->with('auth')->andReturnSelf();
        Log::shouldReceive('log')->once();

        Logger::auth('otp issued');
    });

    it('records an exception with where it came from', function (): void {
        Log::shouldReceive('log')->once()->withArgs(
            fn (string $level, string $message, array $context): bool => $level === 'error'
                && $context['exception'] === RuntimeException::class
        );

        Logger::exception('backup failed', new RuntimeException('disk full'));
    });
});

describe('a channel the product defined', function (): void {
    /*
     * Hardcoding the channel list would mean a product with its own could
     * not use this at all, and reaching for Log:: instead is how a payload
     * gets logged unscrubbed.
     */
    it('writes to any channel the product named', function (): void {
        config(['logging.channels.transactions' => ['driver' => 'null']]);

        Log::shouldReceive('channel')->once()->with('transactions')->andReturnSelf();
        Log::shouldReceive('log')->once();

        Logger::create('transactions', 'a payment settled', ['reference' => 'INV-1']);
    });

    it('scrubs a named channel like any other', function (): void {
        Log::shouldReceive('log')->once()->withArgs(
            fn (string $level, string $message, array $context): bool => ! str_contains((string) $context['password'], 'hunter2')
        );

        Logger::create('errors', 'something went wrong', ['password' => 'hunter2secret']);
    });

    it('defaults a named channel to info and takes a level when given one', function (): void {
        Log::shouldReceive('log')->once()->with('info', 'one', [])->andReturnNull();
        Log::shouldReceive('log')->once()->with('warning', 'two', [])->andReturnNull();

        Logger::create('errors', 'one');
        Logger::create('errors', 'two', [], 'warning');
    });

    it('records an exception at the level the caller chose', function (): void {
        Log::shouldReceive('log')->once()->withArgs(
            fn (string $level): bool => $level === 'warning'
        );

        Logger::exception('could not prune', new RuntimeException('gone'), [], 'warning');
    });
});

describe('what must not be touched', function (): void {
    /*
     * A provider's own document is evidence. Masking a field inside it
     * makes the record disagree with what the provider sent, and a
     * reconciliation against it then fails for the wrong reason.
     */
    it('keeps a provider payload exactly as it arrived', function (): void {
        $scrubbed = Redactor::scrub([
            'password' => 'hunter2secret',
            'payload' => ['phone' => '254712345678', 'token' => 'abcdef123456'],
        ]);

        expect($scrubbed['password'])->not->toContain('hunter2');
        expect($scrubbed['payload'])->toBe(['phone' => '254712345678', 'token' => 'abcdef123456']);
    });

    it('keeps a payload whole however deep the nesting goes', function (): void {
        $scrubbed = Redactor::scrub(['payload' => ['body' => ['secret' => 'keep-me']]]);

        expect($scrubbed['payload']['body']['secret'])->toBe('keep-me');
    });

    /*
     * An exemption list a product cannot close is a hole. 'payload' is a
     * common column name, and one holding user input has to be scrubbable.
     */
    it('lets a product replace the default rather than only add to it', function (): void {
        config(['noria.log.verbatim_keys' => ['raw_response']]);

        $scrubbed = Redactor::scrub(['raw_response' => ['token' => 'keep-me'], 'payload' => ['token' => 'abcdef123456']]);

        expect($scrubbed['raw_response']['token'])->toBe('keep-me');
        expect($scrubbed['payload']['token'])->not->toBe('abcdef123456');
    });

    it('keeps sensitivity lists additive, because those are not holes', function (): void {
        config(['noria.log.pii_keys' => ['kraPin']]);

        expect(Redactor::sensitive('kra_pin'))->toBeTrue();
        expect(Redactor::sensitive('password'))->toBeTrue();
    });
});

describe('an address in the context', function (): void {
    /* A token in a query string is a token. */
    it('drops the query string and keeps the path', function (): void {
        expect(Redactor::scrub(['callback_url' => 'https://example.com/hook?token=secret123'])['callback_url'])
            ->toBe('https://example.com/hook');
    });

    it('recognises the other names an address goes by', function (string $key): void {
        expect(Redactor::scrub([$key => 'https://example.com/x?t=1'])[$key])->toBe('https://example.com/x');
    })->with(['callback_url', 'webhook_uri', 'result_endpoint', 'timeout_callback']);

    it('leaves an address with nothing to drop alone', function (): void {
        expect(Redactor::scrub(['url' => 'https://example.com/hook'])['url'])->toBe('https://example.com/hook');
    });

    it('leaves an ordinary string alone however it ends', function (): void {
        expect(Redactor::scrub(['title' => 'a?b'])['title'])->toBe('a?b');
    });
});

describe('ambient context', function (): void {
    it('adds nothing when the product bound no context', function (): void {
        Log::shouldReceive('log')->once()->with('info', 'a line', [])->andReturnNull();

        Logger::app('a line');
    });

    /* A line joined to a request afterwards is worth far more than one that is not. */
    it('carries what the product says every line should carry', function (): void {
        app()->bind(LogContext::class, StubLogContext::class);

        Log::shouldReceive('log')->once()->withArgs(
            fn (string $level, string $message, array $context): bool => $context['request_id'] === 'req-1'
        );

        Logger::app('a line');
    });

    it('lets the caller override an ambient key, knowing more than the request does', function (): void {
        app()->bind(LogContext::class, StubLogContext::class);

        Log::shouldReceive('log')->once()->withArgs(
            fn (string $level, string $message, array $context): bool => $context['path'] === 'from-the-caller'
        );

        Logger::app('a line', ['path' => 'from-the-caller']);
    });

    it('scrubs the ambient context like any other', function (): void {
        StubLogContext::$context = ['email' => 'ada@example.com'];
        app()->bind(LogContext::class, StubLogContext::class);

        Log::shouldReceive('log')->once()->withArgs(
            fn (string $level, string $message, array $context): bool => ! str_contains((string) $context['email'], 'ada@example.com')
        );

        Logger::app('a line');

        StubLogContext::$context = ['request_id' => 'req-1', 'path' => 'invoices'];
    });
});
