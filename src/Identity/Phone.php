<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Identity;

use Illuminate\Support\Facades\Config;
use Stringable;

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

    private static function plausible(string $digits, ?string $national = null): bool
    {
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return false;
        }

        return $national === null || strlen($national) >= 6;
    }
}
