<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use NoriaLabs\Platform\Auth\Otp;
use NoriaLabs\Platform\Auth\OtpChallenge;
use NoriaLabs\Platform\Auth\OtpOutcome;

it('issues a code of the configured length', function (): void {
    config(['platform.auth.otp.length' => 8]);

    expect(app(Otp::class)->issue('ada@example.com'))->toHaveLength(8);
});

it('never issues a code a leading zero could be eaten from', function (): void {
    $otp = app(Otp::class);

    foreach (range(1, 40) as $ignored) {
        expect(app(Otp::class)->issue('ada@example.com'))->not->toStartWith('0');
    }
});

/* A dump of this table must not sign anybody in. */
it('stores the code hashed, never in the clear', function (): void {
    $code = app(Otp::class)->issue('ada@example.com');

    expect(OtpChallenge::query()->sole()->code_hash)->not->toBe($code);
});

it('accepts the code it issued', function (): void {
    $otp = app(Otp::class);
    $code = $otp->issue('ada@example.com');

    expect($otp->verify('ada@example.com', $code))->toBe(OtpOutcome::Verified);
});

it('treats the address as the same address whatever the casing', function (): void {
    $otp = app(Otp::class);
    $code = $otp->issue('Ada@Example.com ');

    expect($otp->verify('ada@example.com', $code))->toBe(OtpOutcome::Verified);
});

it('refuses a code that was never issued', function (): void {
    expect(app(Otp::class)->verify('ada@example.com', '123456'))->toBe(OtpOutcome::NoChallenge);
});

it('refuses the wrong code', function (): void {
    $otp = app(Otp::class);
    $otp->issue('ada@example.com');

    expect($otp->verify('ada@example.com', '000000'))->toBe(OtpOutcome::Incorrect);
});

it('refuses a code that has expired', function (): void {
    $otp = app(Otp::class);
    $code = $otp->issue('ada@example.com');

    Carbon::setTestNow(Carbon::now()->addMinutes(11));

    expect($otp->verify('ada@example.com', $code))->toBe(OtpOutcome::Expired);

    Carbon::setTestNow();
});

/* A rate limit the cache forgets on restart is not a rate limit. */
it('stops accepting guesses after the configured number of them', function (): void {
    config(['platform.auth.otp.attempts' => 3]);

    $otp = app(Otp::class);
    $code = $otp->issue('ada@example.com');

    foreach (range(1, 3) as $ignored) {
        $otp->verify('ada@example.com', '000000');
    }

    expect($otp->verify('ada@example.com', $code))->toBe(OtpOutcome::Exhausted);
});

it('counts a guess before checking it, so a crash costs an attempt rather than granting one', function (): void {
    $otp = app(Otp::class);
    $otp->issue('ada@example.com');
    $otp->verify('ada@example.com', '000000');

    expect(OtpChallenge::query()->sole()->attempts)->toBe(1);
});

it('cannot be used twice', function (): void {
    $otp = app(Otp::class);
    $code = $otp->issue('ada@example.com');

    $otp->verify('ada@example.com', $code);

    expect($otp->verify('ada@example.com', $code))->toBe(OtpOutcome::NoChallenge);
});

/* Two live codes means the newest mail is not reliably the one that works. */
it('cancels the outstanding code when a new one is asked for', function (): void {
    $otp = app(Otp::class);
    $first = $otp->issue('ada@example.com');
    $second = $otp->issue('ada@example.com');

    expect($otp->verify('ada@example.com', $first))->toBe(OtpOutcome::Incorrect);
    expect($otp->verify('ada@example.com', $second))->toBe(OtpOutcome::Verified);
});

it('keeps one person code separate from another', function (): void {
    $otp = app(Otp::class);
    $ada = $otp->issue('ada@example.com');
    $otp->issue('grace@example.com');

    expect($otp->verify('grace@example.com', $ada))->toBe(OtpOutcome::Incorrect);
});

it('clears out codes nobody will use again', function (): void {
    $otp = app(Otp::class);
    $otp->issue('ada@example.com');

    Carbon::setTestNow(Carbon::now()->addDays(30));
    $otp->prune(7);
    Carbon::setTestNow();

    expect(OtpChallenge::query()->count())->toBe(0);
});
