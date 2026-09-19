<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Log;

use Illuminate\Support\Facades\Config;

/**
 * Masks what should never reach a log aggregator.
 *
 * Keys are normalised before matching - underscores, hyphens and spaces
 * stripped, then lowercased - so Api-Key, api_key and apikey are one key.
 * Suffixes catch the prefixed variants an exact list misses:
 * merchant_api_key normalises to merchantapikey and ends with apikey.
 *
 * Masked rather than dropped, because a support ticket that says the token
 * ended 9f is answerable and one that says [redacted] is not.
 */
final class Redactor
{
    /** @var list<string> */
    private const CREDENTIAL_KEYS = [
        'accesskey', 'accesstoken', 'apikey', 'authorization', 'authtoken',
        'bearer', 'clientid', 'clientsecret', 'consumerkey', 'consumersecret',
        'cookie', 'csrf', 'idempotencykey', 'jwt', 'passkey', 'password',
        'refreshtoken', 'secret', 'secretkey', 'sessionid', 'setcookie',
        'signature', 'sshkey', 'token', 'webhooktoken', 'xsrftoken',
    ];

    /** @var list<string> */
    private const CREDENTIAL_SUFFIXES = [
        'accesskey', 'accesstoken', 'apikey', 'authorization', 'authtoken',
        'bearertoken', 'clientsecret', 'consumersecret', 'encryptionkey',
        'password', 'privatekey', 'refreshtoken', 'secret', 'secretkey',
        'signingkey', 'token', 'verifytoken',
    ];

    /** @var list<string> */
    private const PII_KEYS = [
        'cardnumber', 'cvc', 'cvv', 'dateofbirth', 'dob', 'email',
        'firstname', 'fullname', 'idnumber', 'lastname', 'middlename',
        'mobile', 'mobilenumber', 'msisdn', 'nationalid', 'nationalidnumber',
        'otp', 'passportnumber', 'phone', 'phonenumber', 'pin',
        'securitycode', 'telephone',
    ];

    private function __construct() {}

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function scrub(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (self::sensitive((string) $key)) {
                $payload[$key] = self::mask($value);

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = self::scrub($value);
            }
        }

        return $payload;
    }

    public static function sensitive(string $key): bool
    {
        $normalised = self::normalise($key);

        if (in_array($normalised, self::keys('credential_keys', self::CREDENTIAL_KEYS), true)) {
            return true;
        }

        if (in_array($normalised, self::keys('pii_keys', self::PII_KEYS), true)) {
            return true;
        }

        foreach (self::keys('credential_suffixes', self::CREDENTIAL_SUFFIXES) as $suffix) {
            if (str_ends_with($normalised, $suffix)) {
                return true;
            }
        }

        return false;
    }

    public static function mask(mixed $value): mixed
    {
        if (is_array($value)) {
            return self::scrub($value);
        }

        if (! is_scalar($value)) {
            return '[redacted]';
        }

        $text = (string) $value;
        $length = strlen($text);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return substr($text, 0, 2).str_repeat('*', max(4, $length - 4)).substr($text, -2);
    }

    /**
     * A product adds its own without losing the defaults: a field that is
     * sensitive in one product is sensitive everywhere the log ends up.
     *
     * What the product declares is normalised too, so kra_pin, kraPin and
     * krapin all work. Matching a raw config entry against a normalised key
     * would silently never fire.
     *
     * @param  list<string>  $defaults
     * @return list<string>
     */
    private static function keys(string $name, array $defaults): array
    {
        $extra = Config::array('platform.log.'.$name, []);

        foreach ($extra as $key) {
            if (is_string($key)) {
                $defaults[] = self::normalise($key);
            }
        }

        return $defaults;
    }

    private static function normalise(string $key): string
    {
        return strtolower(str_replace(['_', '-', ' '], '', $key));
    }
}
