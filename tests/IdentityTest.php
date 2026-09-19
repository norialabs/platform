<?php

declare(strict_types=1);

use NoriaLabs\Platform\Identity\Channel;
use NoriaLabs\Platform\Identity\Destination;
use NoriaLabs\Platform\Identity\KeyedHash;
use NoriaLabs\Platform\Identity\Phone;

describe('a phone number', function (): void {
    /*
     * Local, international and imported spellings of one number have to
     * land on one string, or the same customer becomes two accounts.
     */
    it('normalises every spelling of one number to the same string', function (string $raw): void {
        expect(Phone::normalise($raw, 'KE'))->toBe('+254712345678');
    })->with(['0712345678', '712345678', '254712345678', '+254712345678', '+254 712 345 678', '0712-345-678']);

    it('trusts a number that already says which country it is in', function (): void {
        expect(Phone::normalise('+442071234567', 'KE'))->toBe('+442071234567');
    });

    it('normalises against the country it was told', function (): void {
        expect(Phone::normalise('0712345678', 'UG'))->toBe('+256712345678');
    });

    it('refuses a number too short to dial', function (string $raw): void {
        expect(Phone::normalise($raw, 'KE'))->toBeNull();
    })->with(['12345', '', 'not a number', '+1']);

    it('refuses a country it has no code for', function (): void {
        expect(Phone::normalise('0712345678', 'ZZ'))->toBeNull();
        expect(Phone::knows('ZZ'))->toBeFalse();
    });

    it('takes a new market from config rather than a release', function (): void {
        config(['noria.identity.dialling_codes.ZM' => '260']);

        expect(Phone::normalise('0977123456', 'ZM'))->toBe('+260977123456');
    });

    /* Enough to recognise, not enough to dial. */
    it('masks the middle when a log line carries one', function (): void {
        expect(Phone::tryFrom('+254712345678')?->masked())->toBe('+254712***678');
    });
});

describe('a destination', function (): void {
    it('reads an address or a number, whichever it was given', function (): void {
        expect(Destination::tryFrom('ADA@Example.com ')?->value)->toBe('ada@example.com');
        expect(Destination::tryFrom('0712345678')?->value)->toBe('+254712345678');
    });

    it('refuses what is neither', function (string $raw): void {
        expect(Destination::isValid($raw))->toBeFalse();
    })->with(['', '   ', 'not-an-address', 'nope@', '12345']);

    it('refuses an address too long to store', function (): void {
        expect(Destination::isValid(str_repeat('a', 130).'@example.com'))->toBeFalse();
    });

    it('knows which channel can reach it', function (): void {
        $email = Destination::tryFrom('ada@example.com');
        $phone = Destination::tryFrom('+254712345678');

        expect($email?->suits(Channel::Email))->toBeTrue();
        expect($email?->suits(Channel::WhatsApp))->toBeFalse();
        expect($phone?->suits(Channel::Sms))->toBeTrue();
    });

    /* The only form of a destination ever stored in clear. */
    it('masks enough to recognise and not enough to reconstruct', function (): void {
        expect(Destination::tryFrom('ada@example.com')?->masked())->toBe('a**@example.com');
        expect(Destination::tryFrom('+254712345678')?->masked())->toBe('+254712***678');
    });
});

describe('a keyed hash', function (): void {
    it('gives the same value the same hash', function (): void {
        $hash = new KeyedHash('a-key');

        expect($hash->of('ada@example.com'))->toBe($hash->of('ada@example.com'));
    });

    /* A plain digest of a Kenyan mobile is 10^8: seconds of work. */
    it('gives a different hash under a different key', function (): void {
        expect((new KeyedHash('one'))->of('ada@example.com'))
            ->not->toBe((new KeyedHash('two'))->of('ada@example.com'));
    });

    it('recognises the value it hashed and nothing else', function (): void {
        $hash = new KeyedHash('a-key');
        $digest = $hash->of('ada@example.com');

        expect($hash->matches('ada@example.com', $digest))->toBeTrue();
        expect($hash->matches('grace@example.com', $digest))->toBeFalse();
    });

    it('keeps nothing of the value it was given', function (): void {
        expect((new KeyedHash('a-key'))->of('ada@example.com'))->not->toContain('ada');
    });

    it('takes the application key when the product named none', function (): void {
        config(['noria.identity.hash_key' => null, 'app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

        expect((new KeyedHash)->of('ada@example.com'))->toBeString();
    });

    it('refuses to hash with no key at all rather than with an empty one', function (): void {
        config(['noria.identity.hash_key' => null, 'app.key' => null]);

        (new KeyedHash)->of('ada@example.com');
    })->throws(RuntimeException::class, 'needs a key');
});
