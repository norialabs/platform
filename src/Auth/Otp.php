<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Auth;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Issuing and checking a one-time code, and nothing else: no user lookup, no
 * mail, no session. The product decides who may receive one and how it
 * reaches them, because those answers differ per product and this does not.
 *
 * Issuing invalidates whatever was outstanding for the same identifier, so
 * two codes are never live at once and the newest mail is always the right
 * one.
 */
class Otp
{
    /** @return string the plain code, which exists only in this return value */
    public function issue(string $identifier): string
    {
        $code = $this->code();

        $this->pending($identifier)->delete();

        OtpChallenge::query()->create([
            'identifier' => $this->normalise($identifier),
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => Carbon::now()->addMinutes(Config::integer('platform.auth.otp.ttl', 10)),
        ]);

        return $code;
    }

    public function verify(string $identifier, string $code): OtpOutcome
    {
        $challenge = $this->pending($identifier)->latest('created_at')->first();

        if ($challenge === null) {
            return OtpOutcome::NoChallenge;
        }

        if ($challenge->expires_at->isPast()) {
            return OtpOutcome::Expired;
        }

        if ($challenge->attempts >= Config::integer('platform.auth.otp.attempts', 5)) {
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

    /** Rows nobody will use again. Consumed ones are kept for the trail until they age out. */
    public function prune(int $days = 7): int
    {
        $deleted = OtpChallenge::query()
            ->where('expires_at', '<', Carbon::now()->subDays($days))
            ->delete();

        return is_int($deleted) ? $deleted : 0;
    }

    /** @return Builder<OtpChallenge> */
    private function pending(string $identifier)
    {
        return OtpChallenge::query()
            ->where('identifier', $this->normalise($identifier))
            ->whereNull('consumed_at');
    }

    /**
     * Digits only, and never starting with a zero that a spreadsheet or an
     * input mask would eat.
     */
    private function code(): string
    {
        $length = max(4, Config::integer('platform.auth.otp.length', 6));

        $code = (string) random_int(1, 9);

        for ($i = 1; $i < $length; $i++) {
            $code .= (string) random_int(0, 9);
        }

        return $code;
    }

    private function normalise(string $identifier): string
    {
        return Str::lower(trim($identifier));
    }
}
