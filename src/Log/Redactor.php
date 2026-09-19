<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Log;

use Illuminate\Support\Facades\Config;

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
     * @param  bool  $verbatim  inside a subtree that must not be touched
     * @return array<array-key, mixed>
     */
    public static function scrub(array $payload, bool $verbatim = false): array
    {
        foreach ($payload as $key => $value) {
            $name = (string) $key;
            $inside = $verbatim || self::verbatim($name);

            if (is_array($value)) {
                $payload[$key] = self::scrub($value, $inside);

                continue;
            }

            if ($inside) {
                continue;
            }

            if (self::sensitive($name)) {
                $payload[$key] = self::mask($value);

                continue;
            }

            if (self::addressed($name) && is_scalar($value)) {
                $payload[$key] = self::withoutQuery((string) $value);
            }
        }

        return $payload;
    }

    public static function verbatim(string $key): bool
    {
        return in_array(self::normalise($key), self::listed('verbatim_keys', ['payload']), true);
    }

    private static function addressed(string $key): bool
    {
        foreach (self::listed('address_suffixes', ['url', 'uri', 'endpoint', 'callback']) as $suffix) {
            if (str_ends_with(self::normalise($key), $suffix)) {
                return true;
            }
        }

        return false;
    }

    private static function withoutQuery(string $value): string
    {
        $mark = strpos($value, '?');

        return $mark === false ? $value : substr($value, 0, $mark);
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

        if (! is_scalar($value) || Config::string('noria.log.mask', 'redact') !== 'partial') {
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
     * @param  list<string>  $defaults
     * @return list<string>
     */
    private static function listed(string $name, array $defaults): array
    {
        $configured = Config::array('noria.log.'.$name, []);
        $named = array_values(array_filter($configured, is_string(...)));

        return $named === [] ? $defaults : array_map(self::normalise(...), $named);
    }

    /**
     * @param  list<string>  $defaults
     * @return list<string>
     */
    private static function keys(string $name, array $defaults): array
    {
        $extra = Config::array('noria.log.'.$name, []);

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
