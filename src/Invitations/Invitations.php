<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Invitations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use NoriaLabs\Platform\Contracts\Courier;
use NoriaLabs\Platform\Identity\Channel;
use NoriaLabs\Platform\Identity\Destination;
use NoriaLabs\Platform\Identity\KeyedHash;
use NoriaLabs\Platform\Platform;
use NoriaLabs\Platform\Tenancy\Tenancy;

/**
 * Inviting somebody into a workspace, and letting them in.
 *
 * The package owns the rules - one open invitation per destination, a
 * hashed single-use token, a deadline, and the answer that the person
 * accepting is the person invited. What a role means, and who becomes a
 * member, stay with the product: accept() hands back the invitation and
 * the caller writes the membership.
 */
class Invitations
{
    public function __construct(
        private KeyedHash $hash,
        private Tenancy $tenancy,
        private ?Courier $courier = null,
    ) {}

    /**
     * @param  array<string, mixed>  $context  anything the courier needs to write the message
     * @return array{invitation: Invitation, token: string} the token exists only in this return value
     */
    public function invite(
        Destination $to,
        string $role,
        Channel $channel = Channel::Email,
        ?string $invitedBy = null,
        array $context = [],
    ): array {
        $token = Str::random(64);

        // Reissuing replaces the open one rather than adding a second, so
        // the newest message is always the one that works.
        $this->open($to)->delete();

        $invitation = Platform::invitationModel()::query()->create([
            'destination_hash' => $this->hash->of($to->value),
            'destination_hint' => $to->masked(),
            'channel' => $channel->value,
            'role' => $role,
            'token_hash' => $this->hash->of($token),
            'invited_by' => $invitedBy,
            'expires_at' => Carbon::now()->addDays(Config::integer('noria.invitations.ttl_days', 7)),
        ]);

        $this->courier?->deliver($to, $channel, 'invitation', [...$context, 'token' => $token]);

        return ['invitation' => $invitation, 'token' => $token];
    }

    /**
     * Whatever is still outstanding for this destination.
     *
     * @return Builder<Invitation>
     */
    public function open(Destination $to): Builder
    {
        /** @var Builder<Invitation> $query */
        $query = Platform::invitationModel()::query();

        return $query
            ->where('destination_hash', $this->hash->of($to->value))
            ->whereNull('accepted_at')
            ->whereNull('revoked_at');
    }

    /**
     * The invitation a token opens, from outside any workspace.
     *
     * Read under the lookup setting rather than a workspace, because
     * somebody accepting has not joined one yet. The policy admits exactly
     * the row whose token hash matches and nothing else.
     */
    public function find(string $token): Invitation
    {
        $hash = $this->hash->of($token);

        $invitation = $this->tenancy->withGuc(
            [Config::string('noria.invitations.token_guc', 'app.invitation_token') => $hash],
            fn () => Platform::invitationModel()::query()->where('token_hash', $hash)->first(),
        );

        if (! $invitation instanceof Invitation) {
            throw InvitationOutcome::unknown();
        }

        if ($invitation->hasExpired()) {
            throw InvitationOutcome::expired();
        }

        if (! $invitation->isOpen()) {
            throw InvitationOutcome::unknown();
        }

        return $invitation;
    }

    /**
     * Marks the invitation used, inside the workspace it belongs to, and
     * hands it back for the caller to make a member from.
     */
    public function accept(string $token, Destination $by, ?string $acceptorId = null): Invitation
    {
        $invitation = $this->find($token);

        // The person accepting has to be the person invited, or a leaked
        // link is an account in somebody else's workspace.
        if (! hash_equals($invitation->destination_hash, $this->hash->of($by->value))) {
            throw InvitationOutcome::wrongRecipient();
        }

        $workspaceId = $invitation->workspace_id;

        $mark = function () use ($invitation, $acceptorId): Invitation {
            $invitation->forceFill(['accepted_at' => Carbon::now(), 'accepted_by' => $acceptorId])->save();

            return $invitation;
        };

        return $workspaceId === null ? $mark() : $this->tenancy->run($workspaceId, $mark);
    }

    public function revoke(Invitation $invitation): Invitation
    {
        if (! $invitation->isOpen()) {
            throw InvitationOutcome::notPending();
        }

        $invitation->forceFill(['revoked_at' => Carbon::now()])->save();

        return $invitation;
    }

    /** Rows nobody will use again. */
    public function prune(int $days = 30): int
    {
        $deleted = Platform::invitationModel()::query()
            ->where('expires_at', '<', Carbon::now()->subDays($days))
            ->delete();

        return is_int($deleted) ? $deleted : 0;
    }
}
