<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use NoriaLabs\Platform\Log\Logger;
use NoriaLabs\Platform\Log\Redactor;

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
        config(['platform.log.pii_keys' => ['kraPin']]);

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
