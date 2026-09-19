<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Auth;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use NoriaLabs\Platform\Identity\Channel;
use NoriaLabs\Platform\Identity\Destination;
use NoriaLabs\Platform\Identity\KeyedHash;
use NoriaLabs\Platform\Platform;

/**
 * Issuing and checking a one-time code, and nothing else: no user lookup,
 * no mail, no session, because those answers differ per product and this
 * does not.
 *
 * Neither the code nor the address it went to is stored in clear. A dump of
 * this table signs nobody in and tells nobody who was signing in; the hint
 * is the part a screen can show back.
 */
class Otp
{
    public function __construct(private KeyedHash $hash) {}

    /**
     * @return string the plain code, which exists only in this return value
     *
     * @throws OtpThrottled when one was issued to this destination too recently
     */
    public function issue(Destination $to, Channel $channel = Channel::Email): string
    {
        $wait = $this->secondsUntilNextIssue($to);

        if ($wait !== null) {
            throw new OtpThrottled("Another code may be requested in {$wait} seconds.", $wait);
        }

        $code = $this->code();

        // Two live codes means the newest message is not reliably the one
        // that works.
        $this->pending($to)->delete();

        Platform::otpChallengeModel()::query()->create([
            'destination_hash' => $this->hash->of($to->value),
            'destination_hint' => $to->masked(),
            'channel' => $channel->value,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => Carbon::now()->addMinutes(Config::integer('noria.auth.otp.ttl', 10)),
        ]);

        return $code;
    }

    public function verify(Destination $to, string $code): OtpOutcome
    {
        $challenge = $this->pending($to)->latest('created_at')->first();

        if ($challenge === null) {
            return OtpOutcome::NoChallenge;
        }

        if ($challenge->expires_at->isPast()) {
            return OtpOutcome::Expired;
        }

        if ($challenge->attempts >= Config::integer('noria.auth.otp.attempts', 5)) {
            return OtpOutcome::Exhausted;
        }

        // Counted before the comparison, so a crash mid-check costs an
        // attempt rather than granting an unlimited supply of them.
        $challenge->increment('attempts');

        if (! Hash::check($code, $challenge->code_hash)) {
            return OtpOutcome::Incorrect;
        }

        $challenge->forceFill(['consumed_at' => Carbon::now()])->save();

        return OtpOutcome::Verified;
    }

    /**
     * How long until this destination may ask for another code, or null
     * when it may ask now. Counted on the row rather than in the cache, so
     * a restart does not hand out a fresh allowance.
     */
    public function secondsUntilNextIssue(Destination $to): ?int
    {
        $throttle = Config::integer('noria.auth.otp.throttle', 60);

        if ($throttle <= 0) {
            return null;
        }

        $last = Platform::otpChallengeModel()::query()
            ->where('destination_hash', $this->hash->of($to->value))
            ->latest('created_at')
            ->first();

        $issuedAt = $last?->created_at;

        if ($issuedAt === null) {
            return null;
        }

        $ready = $issuedAt->addSeconds($throttle);

        return $ready->isFuture() ? (int) ceil(now()->diffInSeconds($ready, absolute: true)) : null;
    }

    /** Rows nobody will use again. Consumed ones are kept for the trail until they age out. */
    public function prune(int $days = 7): int
    {
        $deleted = Platform::otpChallengeModel()::query()
            ->where('expires_at', '<', Carbon::now()->subDays($days))
            ->delete();

        return is_int($deleted) ? $deleted : 0;
    }

    /** @return Builder<OtpChallenge> */
    private function pending(Destination $to)
    {
        return Platform::otpChallengeModel()::query()
            ->where('destination_hash', $this->hash->of($to->value))
            ->whereNull('consumed_at');
    }

    /**
     * Digits only, and never starting with a zero that a spreadsheet or an
     * input mask would eat.
     */
    private function code(): string
    {
        $length = max(4, Config::integer('noria.auth.otp.length', 6));

        $code = (string) random_int(1, 9);

        for ($i = 1; $i < $length; $i++) {
            $code .= (string) random_int(0, 9);
        }

        return $code;
    }
}
