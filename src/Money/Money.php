<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Money;

use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Number;
use InvalidArgumentException;

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

    public function shareOfBasisPoints(int $basisPoints): self
    {
        return new self(intdiv(self::checkedMultiply($this->minor, $basisPoints), 10_000), $this->currency);
    }

    public static function roundUpMinor(int|float $minor, ?string $currency = null): int
    {
        $step = self::stepFor(strtoupper($currency ?? self::defaultCurrency()));

        return (int) (ceil((float) number_format($minor / $step, 6, '.', '')) * $step);
    }

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

    public function format(?string $locale = null, ?int $precision = null): string
    {
        $digits = $precision ?? self::fractionDigits($this->currency);
        $locale ??= self::localeFor($this->currency);

        return self::withPlainSpaces((string) Number::currency($this->toMajor(), $this->currency, $locale, $digits));
    }

    public static function localeFor(?string $currency = null): string
    {
        $currency = strtoupper($currency ?? self::defaultCurrency());
        $mapped = Config::array('noria.money.locales', [])[$currency] ?? null;

        return is_string($mapped) && $mapped !== ''
            ? $mapped
            : 'en_'.substr($currency, 0, 2);
    }

    public function formatUnitPrice(?string $locale = null): string
    {
        return $this->format($locale, 2);
    }

    public function document(): string
    {
        return self::decimalString($this->minor, self::fractionDigits($this->currency));
    }

    public function rate(): string
    {
        return self::decimalString($this->minor, 2);
    }

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

        $sign = $minor < 0 && $units !== 0 ? '-' : '';
        $whole = intdiv($units, $scale);

        if ($digits === 0) {
            return $sign.$whole;
        }

        return $sign.$whole.'.'.str_pad((string) ($units % $scale), $digits, '0', STR_PAD_LEFT);
    }

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
