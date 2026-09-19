<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Identity;

use Illuminate\Support\Facades\Config;
use Stringable;

/**
 * Phone numbers in E.164, the only form the identity keys, the messaging
 * providers and the deduplication check ever see.
 *
 * Local, international and imported spellings of one number must normalise
 * to the same string or one customer becomes two accounts. The country is a
 * parameter taken from the workspace; a number already in international
 * form is trusted as given.
 */
final class Phone implements Stringable
{
    private function __construct(public readonly string $e164) {}

    public static function tryFrom(?string $raw, ?string $country = null): ?self
    {
        $trimmed = trim((string) $raw);
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($digits === '') {
            return null;
        }

        // Already international: trust the number, only check it is plausible.
        if (str_starts_with($trimmed, '+')) {
            return self::plausible($digits) ? new self('+'.$digits) : null;
        }

        $code = self::diallingCode($country ?? Config::string('noria.identity.country', 'KE'));

        if ($code === null) {
            return null;
        }

        $national = match (true) {
            str_starts_with($digits, $code) && strlen($digits) > strlen($code) => substr($digits, strlen($code)),
            str_starts_with($digits, '0') => substr($digits, 1),
            default => $digits,
        };

        return self::plausible($code.$national, $national) ? new self('+'.$code.$national) : null;
    }

    public static function normalise(?string $raw, ?string $country = null): ?string
    {
        return self::tryFrom($raw, $country)?->e164;
    }

    public static function isValid(?string $raw, ?string $country = null): bool
    {
        return self::tryFrom($raw, $country) !== null;
    }

    public static function knows(string $country): bool
    {
        return self::diallingCode($country) !== null;
    }

    /** What a redacted prompt or a log line may carry: enough to recognise, not enough to dial. */
    public function masked(): string
    {
        return substr($this->e164, 0, 7).'***'.substr($this->e164, -3);
    }

    public function __toString(): string
    {
        return $this->e164;
    }

    private static function diallingCode(string $country): ?string
    {
        $codes = Config::array('noria.identity.dialling_codes', []);
        $code = $codes[strtoupper($country)] ?? null;

        return is_scalar($code) ? (string) $code : null;
    }

    /**
     * At most fifteen digits including the country code, and the national
     * part has its own floor: a five digit "number" with a code bolted on
     * is a typo.
     */
    private static function plausible(string $digits, ?string $national = null): bool
    {
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return false;
        }

        return $national === null || strlen($national) >= 6;
    }
}
