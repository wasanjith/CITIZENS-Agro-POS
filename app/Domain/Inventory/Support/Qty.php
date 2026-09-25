<?php

namespace App\Domain\Inventory\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Quantity helpers. Quantities are DECIMAL(14,3) strings, always in the base unit.
 */
final class Qty
{
    public const SCALE = 3;

    public static function of(BigDecimal|string|int|null $value): BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value->toScale(self::SCALE, RoundingMode::HalfUp);
        }

        return BigDecimal::of((string) ($value ?? '0'))->toScale(self::SCALE, RoundingMode::HalfUp);
    }

    /**
     * "50.000" → "50", "2.500" → "2.5", "-3.000" → "-3".
     */
    public static function format(BigDecimal|string|int|null $value): string
    {
        $text = (string) self::of($value);

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /**
     * Quantity in base units shown in a bigger unit when it divides evenly: 150 kg → "3 bag".
     */
    public static function inUnit(BigDecimal|string $baseQty, string $factor, string $symbol): string
    {
        return self::format(self::of($baseQty)->dividedBy($factor, self::SCALE, RoundingMode::HalfUp)).' '.$symbol;
    }
}
