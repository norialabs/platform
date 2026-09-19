<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Money;

use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Number;
use InvalidArgumentException;

/**
 * An amount in minor units, and the currency it is in.
 *
 * Minor is always hundredths of the major unit, whatever the currency
 * displays. A shilling shows no decimals and is still stored in hundredths,
 * because a tariff of 2.75 per unit has to survive being multiplied by a
 * meter reading before anybody rounds it for a document.
 *
 * Fraction digits are therefore a display decision, not a storage one, and
 * are capped at two: nothing here stores thousandths.
 *
 * Integers throughout. A float cannot hold a third of a shilling and a sum
 * of floats does not reconcile.
 */
final class Money
{
    private const SCALE = 100;

    public function __construct(
        public readonly int $minor,
        public readonly string $currency,
    ) {}

    public static function of(int $minor, ?string $currency = null): self
    {
        return new self($minor, strtoupper($currency ?? self::defaultCurrency()));
    }

    public static function zero(?string $currency = null): self
    {
        return new self(0, strtoupper($currency ?? self::defaultCurrency()));
    }

    /**
     * From what a person typed. Strict, because a silent zero from a
     * mistyped amount is an invoice nobody queries until month end.
     *
     * @throws InvalidArgumentException when the value is not a plain decimal or is out of range
     */
    public static function fromMajor(string $major, ?string $currency = null): self
    {
        $trimmed = trim($major);

        if (preg_match('/^-?\d+(\.\d+)?$/', $trimmed) !== 1) {
            throw new InvalidArgumentException("\"{$major}\" is not a valid amount.");
        }

        [$whole, $fraction] = array_pad(explode('.', ltrim($trimmed, '-'), 2), 2, '');

        if (strlen(ltrim($whole, '0')) > 15) {
            throw new InvalidArgumentException('The resulting amount is outside the supported range.');
        }

        $minor = (int) $whole * self::SCALE + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        // A third decimal rounds the second rather than being dropped.
        if (isset($fraction[2]) && $fraction[2] >= '5') {
            $minor++;
        }

        if (str_starts_with($trimmed, '-')) {
            $minor = -$minor;
        }

        return new self(self::assertWithinBounds($minor), strtoupper($currency ?? self::defaultCurrency()));
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function times(int|float $quantity): self
    {
        return new self(self::checkedMultiply($this->minor, $quantity), $this->currency);
    }

    /** A share in hundredths of a percent, which is how a fee or a levy is quoted. */
    public function shareOfBasisPoints(int $basisPoints): self
    {
        return new self(intdiv(self::checkedMultiply($this->minor, $basisPoints), 10_000), $this->currency);
    }

    /** Up to the next whole step the currency can actually show. */
    public function roundUpToStep(?int $step = null): self
    {
        $step ??= self::stepFor($this->currency);

        if ($step <= 0) {
            throw new InvalidArgumentException('A rounding step must be positive.');
        }

        return new self((int) (ceil($this->minor / $step) * $step), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    public function toMajor(): float
    {
        return $this->minor / self::SCALE;
    }

    /** For a person to read: the local symbol and the currency's own decimals. */
    public function format(?string $locale = null, ?int $precision = null): string
    {
        $digits = $precision ?? self::fractionDigits($this->currency);
        $locale ??= self::localeFor($this->currency);

        return self::withPlainSpaces((string) Number::currency($this->toMajor(), $this->currency, $locale, $digits));
    }

    /**
     * The locale a currency is rendered in.
     *
     * Pinned per currency, never taken from app.locale: ICU renders KES as
     * "KES" under en and "Ksh" under en_KE, so leaving it to configuration
     * means a locale change silently rewrites every amount in the product.
     */
    public static function localeFor(?string $currency = null): string
    {
        $currency = strtoupper($currency ?? self::defaultCurrency());
        $mapped = Config::array('noria.money.locales', [])[$currency] ?? null;

        return is_string($mapped) && $mapped !== ''
            ? $mapped
            : 'en_'.substr($currency, 0, 2);
    }

    /**
     * A unit price always shows both decimals. A tariff of 2.75 per unit
     * rendered as 3 in a currency that displays none is not the price.
     */
    public function formatUnitPrice(?string $locale = null): string
    {
        return $this->format($locale, 2);
    }

    /** For a document: the bare number, no symbol, at the currency's own precision. */
    public function document(): string
    {
        return self::decimalString($this->minor, self::fractionDigits($this->currency));
    }

    /** For a rate: the bare number at two decimals whatever the currency shows. */
    public function rate(): string
    {
        return self::decimalString($this->minor, 2);
    }

    /**
     * How many decimals this currency shows. A display decision: the
     * shilling shows none and is still stored in hundredths.
     */
    public static function fractionDigits(?string $currency = null): int
    {
        $currency = strtoupper($currency ?? self::defaultCurrency());
        $table = Config::array('noria.money.fraction_digits', []);
        $digits = $table[$currency] ?? Config::integer('noria.money.digits', 2);

        return max(0, min(is_numeric($digits) ? (int) $digits : 2, 2));
    }

    public static function maxMinor(): int
    {
        return Config::integer('noria.money.max_minor', 1_000_000_000_000);
    }

    /** @return list<string> the rules for an amount already in minor units */
    public static function rules(bool $positive = true): array
    {
        return ['integer', 'min:'.($positive ? 1 : -self::maxMinor()), 'max:'.self::maxMinor()];
    }

    /**
     * The rules for an amount a person typed, which arrives as a string and
     * has to survive being read as one.
     *
     * @param  int|null  $minMinor  the smallest amount accepted, or null for no lower bound
     * @return list<mixed>
     */
    public static function majorRules(?string $currency = null, ?int $minMinor = 1): array
    {
        $currency = strtoupper($currency ?? self::defaultCurrency());

        return [
            'bail',
            'string',
            function (string $attribute, mixed $value, Closure $fail) use ($currency, $minMinor): void {
                try {
                    $amount = self::fromMajor(is_scalar($value) ? (string) $value : '', $currency);
                } catch (InvalidArgumentException $exception) {
                    $fail($exception->getMessage());

                    return;
                }

                if ($minMinor !== null && $amount->minor < $minMinor) {
                    $fail('The :attribute must be at least '.self::of($minMinor, $currency)->format().'.');
                }
            },
        ];
    }

    /**
     * A quantity may be fractional - a meter reading, a part hour - so the
     * product is taken as a float and rounded once, at the end.
     */
    public static function checkedMultiply(int $minor, int|float $quantity): int
    {
        $product = (float) $minor * (float) $quantity;

        if (! is_finite($product) || abs($product) > self::maxMinor()) {
            throw new InvalidArgumentException('The resulting amount is outside the supported range.');
        }

        return (int) round($product);
    }

    public static function assertWithinBounds(int $minor): int
    {
        if (abs($minor) > self::maxMinor()) {
            throw new InvalidArgumentException('The resulting amount is outside the supported range.');
        }

        return $minor;
    }

    /** The smallest step this currency can show, in minor units. */
    private static function stepFor(string $currency): int
    {
        return max(1, (int) (self::SCALE / (10 ** self::fractionDigits($currency))));
    }

    private static function decimalString(int $minor, int $digits): string
    {
        $digits = max(0, min($digits, 2));
        $scale = 10 ** $digits;
        $divisor = intdiv(self::SCALE, $scale);

        $absolute = abs($minor);
        $units = $divisor === 1
            ? $absolute
            : intdiv($absolute + intdiv($divisor, 2), $divisor);

        // Negative zero is not an amount anybody wants on a document.
        $sign = $minor < 0 && $units !== 0 ? '-' : '';
        $whole = intdiv($units, $scale);

        if ($digits === 0) {
            return $sign.$whole;
        }

        return $sign.$whole.'.'.str_pad((string) ($units % $scale), $digits, '0', STR_PAD_LEFT);
    }

    /** Number::currency uses non-breaking spaces, which no CSV or ASCII check survives. */
    private static function withPlainSpaces(string $value): string
    {
        return str_replace(["\u{00A0}", "\u{202F}"], ' ', $value);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Cannot combine {$this->currency} with {$other->currency}.");
        }
    }

    private static function defaultCurrency(): string
    {
        return Config::string('noria.money.currency', 'KES');
    }
}
