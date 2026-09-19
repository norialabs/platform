<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use NoriaLabs\Platform\Auth\Otp;
use NoriaLabs\Platform\Auth\OtpChallenge;
use NoriaLabs\Platform\Auth\OtpOutcome;
use NoriaLabs\Platform\Auth\OtpThrottled;
use NoriaLabs\Platform\Auth\ProviderProfile;
use NoriaLabs\Platform\Auth\SocialState;
use NoriaLabs\Platform\Identity\Destination;
use NoriaLabs\Platform\Platform;
use NoriaLabs\Platform\Tests\Fixtures\HostOtpChallenge;

function to(string $raw): Destination
{
    return Destination::tryFrom($raw) ?? throw new InvalidArgumentException("[{$raw}] is not a destination.");
}

it('issues a code of the configured length', function (): void {
    config(['platform.auth.otp.length' => 8]);

    expect(app(Otp::class)->issue(to('ada@example.com')))->toHaveLength(8);
});

it('never issues a code a leading zero could be eaten from', function (): void {
    $otp = app(Otp::class);

    foreach (range(1, 40) as $n) {
        expect($otp->issue(to("person{$n}@example.com")))->not->toStartWith('0');
    }
});

/* A dump of this table must sign nobody in and name nobody. */
it('stores neither the code nor the address in the clear', function (): void {
    $code = app(Otp::class)->issue(to('ada@example.com'));

    $challenge = OtpChallenge::query()->sole();

    expect($challenge->code_hash)->not->toBe($code);
    expect($challenge->destination_hash)->not->toContain('ada@example.com');
    expect($challenge->destination_hint)->toBe('a**@example.com');
});

it('accepts the code it issued', function (): void {
    $otp = app(Otp::class);
    $code = $otp->issue(to('ada@example.com'));

    expect($otp->verify(to('ada@example.com'), $code))->toBe(OtpOutcome::Verified);
});

it('treats the address as the same address whatever the casing', function (): void {
    $otp = app(Otp::class);
    $code = $otp->issue(to('Ada@Example.com '));

    expect($otp->verify(to('ada@example.com'), $code))->toBe(OtpOutcome::Verified);
});

it('refuses a code that was never issued', function (): void {
    expect(app(Otp::class)->verify(to('ada@example.com'), '123456'))->toBe(OtpOutcome::NoChallenge);
});

it('refuses the wrong code', function (): void {
    $otp = app(Otp::class);
    $otp->issue(to('ada@example.com'));

    expect($otp->verify(to('ada@example.com'), '000000'))->toBe(OtpOutcome::Incorrect);
});

it('refuses a code that has expired', function (): void {
    $otp = app(Otp::class);
    $code = $otp->issue(to('ada@example.com'));

    Carbon::setTestNow(Carbon::now()->addMinutes(11));

    expect($otp->verify(to('ada@example.com'), $code))->toBe(OtpOutcome::Expired);

    Carbon::setTestNow();
});

/* A rate limit the cache forgets on restart is not a rate limit. */
it('stops accepting guesses after the configured number of them', function (): void {
    config(['platform.auth.otp.attempts' => 3]);

    $otp = app(Otp::class);
    $code = $otp->issue(to('ada@example.com'));

    foreach (range(1, 3) as $ignored) {
        $otp->verify(to('ada@example.com'), '000000');
    }

    expect($otp->verify(to('ada@example.com'), $code))->toBe(OtpOutcome::Exhausted);
});

it('counts a guess before checking it, so a crash costs an attempt rather than granting one', function (): void {
    $otp = app(Otp::class);
    $otp->issue(to('ada@example.com'));
    $otp->verify(to('ada@example.com'), '000000');

    expect(OtpChallenge::query()->sole()->attempts)->toBe(1);
});

it('cannot be used twice', function (): void {
    $otp = app(Otp::class);
    $code = $otp->issue(to('ada@example.com'));

    $otp->verify(to('ada@example.com'), $code);

    expect($otp->verify(to('ada@example.com'), $code))->toBe(OtpOutcome::NoChallenge);
});

/* Two live codes means the newest mail is not reliably the one that works. */
it('cancels the outstanding code when a new one is asked for', function (): void {
    config(['platform.auth.otp.throttle' => 0]);

    $otp = app(Otp::class);
    $first = $otp->issue(to('ada@example.com'));
    $second = $otp->issue(to('ada@example.com'));

    expect($otp->verify(to('ada@example.com'), $first))->toBe(OtpOutcome::Incorrect);
    expect($otp->verify(to('ada@example.com'), $second))->toBe(OtpOutcome::Verified);
});

it('keeps one person code separate from another', function (): void {
    $otp = app(Otp::class);
    $ada = $otp->issue(to('ada@example.com'));
    $otp->issue(to('grace@example.com'));

    expect($otp->verify(to('grace@example.com'), $ada))->toBe(OtpOutcome::Incorrect);
});

it('clears out codes nobody will use again', function (): void {
    $otp = app(Otp::class);
    $otp->issue(to('ada@example.com'));

    Carbon::setTestNow(Carbon::now()->addDays(30));
    $otp->prune(7);
    Carbon::setTestNow();

    expect(OtpChallenge::query()->count())->toBe(0);
});

describe('asking too often', function (): void {
    /* A limit the cache forgets on restart is not a limit, so it is on the row. */
    it('refuses a second code asked for too soon', function (): void {
        $otp = app(Otp::class);
        $otp->issue(to('ada@example.com'));

        $otp->issue(to('ada@example.com'));
    })->throws(OtpThrottled::class);

    it('says how long the caller has to wait', function (): void {
        config(['platform.auth.otp.throttle' => 90]);

        $otp = app(Otp::class);
        $otp->issue(to('ada@example.com'));

        expect($otp->secondsUntilNextIssue(to('ada@example.com')))->toBeGreaterThan(80);
    });

    it('lets the next code through once the wait is over', function (): void {
        $otp = app(Otp::class);
        $otp->issue(to('ada@example.com'));

        Carbon::setTestNow(Carbon::now()->addSeconds(61));

        expect($otp->issue(to('ada@example.com')))->toBeString();

        Carbon::setTestNow();
    });

    it('holds one person up without holding anybody else up', function (): void {
        $otp = app(Otp::class);
        $otp->issue(to('ada@example.com'));

        expect($otp->issue(to('grace@example.com')))->toBeString();
    });

    it('does not hold anybody up when the product turned the limit off', function (): void {
        config(['platform.auth.otp.throttle' => 0]);

        $otp = app(Otp::class);
        $otp->issue(to('ada@example.com'));

        expect($otp->issue(to('ada@example.com')))->toBeString();
    });
});

describe('a round trip through a provider', function (): void {
    it('accepts back the state it issued', function (): void {
        $state = app(SocialState::class);

        expect($state->claim($state->issue('google')))
            ->toBe(['provider' => 'google', 'actor_id' => null]);
    });

    /* A code replayed with the same state must find nothing. */
    it('accepts a state once and never again', function (): void {
        $state = app(SocialState::class);
        $issued = $state->issue('google');

        $state->claim($issued);

        expect($state->claim($issued))->toBeNull();
    });

    it('refuses a state nobody issued', function (): void {
        expect(app(SocialState::class)->claim('made-up'))->toBeNull();
    });

    /* Begun by nobody is a sign-in; begun by somebody is a link. */
    it('remembers who began the round trip, which is what it meant by it', function (): void {
        $state = app(SocialState::class);

        expect($state->claim($state->issue('google', 'user-1'))['actor_id'])->toBe('user-1');
    });

    it('forgets a state the caller took too long to come back with', function (): void {
        config(['platform.auth.social.state_ttl' => 1]);

        $state = app(SocialState::class);
        $issued = $state->issue('google');

        Carbon::setTestNow(Carbon::now()->addMinutes(2));

        expect($state->claim($issued))->toBeNull();

        Carbon::setTestNow();
    });

    it('believes the provider only when it says the address is verified', function (): void {
        $verified = ProviderProfile::make('google', '1', 'ada@example.com', raw: ['email_verified' => true]);
        $older = ProviderProfile::make('google', '1', 'ada@example.com', raw: ['verified_email' => 'true']);
        $silent = ProviderProfile::make('google', '1', 'ada@example.com');

        expect($verified->emailVerified)->toBeTrue();
        expect($older->emailVerified)->toBeTrue();
        expect($silent->emailVerified)->toBeFalse();
    });

    /* A debugging aid, not a credential store. */
    it('keeps no provider token in what it hands back', function (): void {
        $profile = ProviderProfile::make('google', '1', 'ada@example.com', raw: [
            'access_token' => 'secret', 'refresh_token' => 'secret', 'locale' => 'en',
        ]);

        expect($profile->raw)->toBe(['locale' => 'en']);
    });
});

describe('substituting the model', function (): void {
    it('issues and verifies through the model the host substituted', function (): void {
        Platform::useOtpChallengeModel(HostOtpChallenge::class);

        $otp = app(Otp::class);
        $code = $otp->issue(to('ada@example.com'));

        expect(HostOtpChallenge::query()->sole())->toBeInstanceOf(HostOtpChallenge::class);
        expect($otp->verify(to('ada@example.com'), $code))->toBe(OtpOutcome::Verified);
    });

    it('refuses a substitute that is not a sign-in code', function (): void {
        Platform::useOtpChallengeModel(stdClass::class);
    })->throws(InvalidArgumentException::class);
});
