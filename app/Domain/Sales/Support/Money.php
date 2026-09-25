<?php

namespace App\Domain\Sales\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Money helpers. Amounts are DECIMAL(15,2) strings; arithmetic uses brick/math, never floats.
 */
final class Money
{
    public static function of(BigDecimal|string|int|float|null $value): BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value->toScale(2, RoundingMode::HalfUp);
        }

        $text = trim((string) ($value ?? '0'));

        return BigDecimal::of($text === '' ? '0' : $text)->toScale(2, RoundingMode::HalfUp);
    }

    public static function zero(): BigDecimal
    {
        return BigDecimal::of('0.00');
    }

    /**
     * "Rs. 12,450.00" style without the prefix: "12,450.00".
     */
    public static function format(BigDecimal|string|int|float|null $value): string
    {
        return number_format((float) (string) self::of($value), 2);
    }

    /**
     * Percentage of $part in $whole, 2 decimals ("0.00" when $whole is zero).
     */
    public static function percent(BigDecimal $part, BigDecimal $whole): BigDecimal
    {
        if ($whole->isZero()) {
            return BigDecimal::of('0.00');
        }

        return $part->multipliedBy(100)->dividedBy($whole, 2, RoundingMode::HalfUp);
    }
}
