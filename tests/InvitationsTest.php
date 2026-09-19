<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use NoriaLabs\Platform\Contracts\Courier;
use NoriaLabs\Platform\Identity\Channel;
use NoriaLabs\Platform\Identity\Destination;
use NoriaLabs\Platform\Invitations\Invitation;
use NoriaLabs\Platform\Invitations\InvitationOutcome;
use NoriaLabs\Platform\Invitations\Invitations;
use NoriaLabs\Platform\Platform;
use NoriaLabs\Platform\Tests\Fixtures\HostInvitation;
use NoriaLabs\Platform\Tests\Fixtures\StubCourier;

function ada(): Destination
{
    return Destination::tryFrom('ada@example.com') ?? throw new RuntimeException('bad fixture');
}

function grace(): Destination
{
    return Destination::tryFrom('grace@example.com') ?? throw new RuntimeException('bad fixture');
}

beforeEach(function (): void {
    StubCourier::$sent = [];
    app()->bind(Courier::class, StubCourier::class);
});

describe('inviting somebody', function (): void {
    /*
     * A dump of this table lets nobody accept anything and tells nobody
     * who was invited. The hint is the part a screen shows back.
     */
    it('keeps neither the token nor the address in the clear', function (): void {
        ['token' => $token] = app(Invitations::class)->invite(ada(), 'member');

        $invitation = Invitation::query()->sole();

        expect($invitation->token_hash)->not->toBe($token);
        expect($invitation->destination_hash)->not->toContain('ada@example.com');
        expect($invitation->destination_hint)->toBe('a**@example.com');
    });

    it('hands the token back once, for the message and nowhere else', function (): void {
        ['token' => $token] = app(Invitations::class)->invite(ada(), 'member');

        expect($token)->toHaveLength(64);
    });

    it('carries the role the product asked for', function (): void {
        app(Invitations::class)->invite(ada(), 'billing-admin');

        expect(Invitation::query()->sole()->role)->toBe('billing-admin');
    });

    it('gives the courier the token and lets the product write the words', function (): void {
        app(Invitations::class)->invite(ada(), 'member', context: ['workspace' => 'Acme']);

        expect(StubCourier::$sent)->toHaveCount(1);
        expect(StubCourier::$sent[0]['kind'])->toBe('invitation');
        expect(StubCourier::$sent[0]['context']['workspace'])->toBe('Acme');
    });

    it('reaches somebody on whatsapp as readily as by mail', function (): void {
        $phone = Destination::tryFrom('0712345678');

        app(Invitations::class)->invite($phone, 'member', Channel::WhatsApp);

        expect(Invitation::query()->sole()->channel)->toBe('whatsapp');
    });

    /* Two live invitations means the newest message is not the one that works. */
    it('replaces an outstanding invitation rather than adding a second', function (): void {
        $invitations = app(Invitations::class);
        ['token' => $first] = $invitations->invite(ada(), 'member');
        ['token' => $second] = $invitations->invite(ada(), 'admin');

        expect(Invitation::query()->count())->toBe(1);
        expect($invitations->find($second)->role)->toBe('admin');

        $invitations->find($first);
    })->throws(InvitationOutcome::class);

    it('leaves one person invitation alone when another is invited', function (): void {
        $invitations = app(Invitations::class);
        $invitations->invite(ada(), 'member');
        $invitations->invite(grace(), 'member');

        expect(Invitation::query()->count())->toBe(2);
    });
});

describe('accepting one', function (): void {
    it('marks it used and hands it back for the product to make a member from', function (): void {
        $invitations = app(Invitations::class);
        ['token' => $token] = $invitations->invite(ada(), 'member');

        $accepted = $invitations->accept($token, ada(), 'user-1');

        expect($accepted->accepted_at)->not->toBeNull();
        expect($accepted->accepted_by)->toBe('user-1');
        expect($accepted->role)->toBe('member');
    });

    /* A leaked link must not become an account in somebody else's workspace. */
    it('refuses somebody who is not the person invited', function (): void {
        $invitations = app(Invitations::class);
        ['token' => $token] = $invitations->invite(ada(), 'member');

        $invitations->accept($token, grace());
    })->throws(InvitationOutcome::class, 'different address');

    it('cannot be accepted twice', function (): void {
        $invitations = app(Invitations::class);
        ['token' => $token] = $invitations->invite(ada(), 'member');
        $invitations->accept($token, ada());

        $invitations->accept($token, ada());
    })->throws(InvitationOutcome::class);

    it('refuses a token nobody issued', function (): void {
        app(Invitations::class)->find('made-up-token');
    })->throws(InvitationOutcome::class, 'no longer valid');

    it('refuses one whose deadline has passed', function (): void {
        $invitations = app(Invitations::class);
        ['token' => $token] = $invitations->invite(ada(), 'member');

        Carbon::setTestNow(Carbon::now()->addDays(8));

        try {
            $invitations->accept($token, ada());
        } finally {
            Carbon::setTestNow();
        }
    })->throws(InvitationOutcome::class, 'expired');

    it('takes its deadline from config', function (): void {
        config(['platform.invitations.ttl_days' => 1]);

        app(Invitations::class)->invite(ada(), 'member');

        expect(Invitation::query()->sole()->expires_at->diffInDays(Carbon::now(), absolute: true))
            ->toBeLessThan(2);
    });
});

describe('withdrawing one', function (): void {
    it('stops it being accepted afterwards', function (): void {
        $invitations = app(Invitations::class);
        ['invitation' => $invitation, 'token' => $token] = $invitations->invite(ada(), 'member');

        $invitations->revoke($invitation);

        $invitations->accept($token, ada());
    })->throws(InvitationOutcome::class);

    it('cannot be withdrawn twice', function (): void {
        $invitations = app(Invitations::class);
        ['invitation' => $invitation] = $invitations->invite(ada(), 'member');
        $invitations->revoke($invitation);

        $invitations->revoke($invitation);
    })->throws(InvitationOutcome::class, 'open invitation');
});

describe('housekeeping', function (): void {
    it('clears out invitations nobody will use again', function (): void {
        app(Invitations::class)->invite(ada(), 'member');

        Carbon::setTestNow(Carbon::now()->addDays(60));
        $removed = app(Invitations::class)->prune(30);
        Carbon::setTestNow();

        expect($removed)->toBe(1);
    });

    it('reads through the model the host substituted', function (): void {
        Platform::useInvitationModel(HostInvitation::class);

        app(Invitations::class)->invite(ada(), 'member');

        expect(HostInvitation::query()->sole())->toBeInstanceOf(HostInvitation::class);
    });
});
