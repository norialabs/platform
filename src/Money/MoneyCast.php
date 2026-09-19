<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * An integer column read as Money, with the currency taken from a sibling
 * column where there is one.
 *
 * A fixed fallback would mislabel every amount belonging to any other
 * country, so the row is asked first and the configured default only
 * answers when the row says nothing.
 *
 * @implements CastsAttributes<Money, Money|int>
 */
class MoneyCast implements CastsAttributes
{
    public function __construct(private string $currencyKey = 'currency') {}

    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return Money::of(is_numeric($value) ? (int) $value : 0, $this->currencyFor($model, $attributes));
    }

    /** @param array<string, mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof Money ? $value->minor : (is_numeric($value) ? (int) $value : 0);
    }

    /** @param array<string, mixed> $attributes */
    private function currencyFor(Model $model, array $attributes): ?string
    {
        $currency = $attributes[$this->currencyKey] ?? $model->getAttribute($this->currencyKey);

        return is_string($currency) && $currency !== '' ? strtoupper($currency) : null;
    }
}
