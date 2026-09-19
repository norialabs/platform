<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Money;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Number;
use InvalidArgumentException;
use RuntimeException;

/**
 * An amount in minor units, and the currency it is in.
 *
 * Integers throughout: a float cannot hold a third of a shilling and a sum
 * of floats does not reconcile. Mixing currencies throws rather than
 * silently adding, because the row that did it is the one worth finding.
 */
final class Money
{
    /**
     * Well inside the range where an int64 multiplication cannot overflow,
     * which is what checkedMultiply is guarding.
     */
    public const MAX_MINOR = 9_000_000_000_000_000;

    public function __construct(
        public readonly int $minor,
        public readonly string $currency,
    ) {
        self::assertWithinBounds($minor);
    }

    public static function of(int $minor, ?string $currency = null): self
    {
        return new self($minor, strtoupper($currency ?? self::defaultCurrency()));
    }

    public static function zero(?string $currency = null): self
    {
        return new self(0, strtoupper($currency ?? self::defaultCurrency()));
    }

    /** From what a person typed: 12.34 becomes 1234 where there are two minor digits. */
    public static function fromMajor(int|float|string $major, ?string $currency = null): self
    {
        $scale = 10 ** self::fractionDigits();
        $minor = (int) round(((float) $major) * $scale);

        return new self($minor, strtoupper($currency ?? self::defaultCurrency()));
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

    public function times(int $factor): self
    {
        return new self(self::checkedMultiply($this->minor, $factor), $this->currency);
    }

    /** A share in hundredths of a percent, which is how a fee or a levy is quoted. */
    public function shareOfBasisPoints(int $basisPoints): self
    {
        return new self(intdiv(self::checkedMultiply($this->minor, $basisPoints), 10_000), $this->currency);
    }

    /** Up to the next whole step, for a tariff band or a minimum charge. */
    public function roundUpToStep(int $step): self
    {
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
        return $this->minor / (10 ** self::fractionDigits());
    }

    public function format(?string $locale = null): string
    {
        return (string) Number::currency($this->toMajor(), $this->currency, $locale);
    }

    public static function fractionDigits(): int
    {
        return Config::integer('noria.money.minor_units', 2);
    }

    public static function maxMinor(): int
    {
        return self::MAX_MINOR;
    }

    /**
     * Overflow is silent in PHP: an int product past PHP_INT_MAX becomes a
     * float, and the amount that comes back is wrong rather than refused.
     * Checked before the multiply, because after it the evidence is gone.
     */
    public static function checkedMultiply(int $value, int $factor): int
    {
        if ($value !== 0 && $factor !== 0 && abs($value) > intdiv(PHP_INT_MAX, abs($factor))) {
            throw new RuntimeException('That multiplication overflows an integer amount.');
        }

        $product = $value * $factor;

        self::assertWithinBounds($product);

        return $product;
    }

    public static function assertWithinBounds(int $minor): void
    {
        if (abs($minor) > self::MAX_MINOR) {
            throw new RuntimeException('That amount is outside the range this system handles.');
        }
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
