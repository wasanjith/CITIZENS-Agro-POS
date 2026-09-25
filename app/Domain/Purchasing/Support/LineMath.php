<?php

namespace App\Domain\Purchasing\Support;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Support\Qty;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * Exact arithmetic for purchase document lines (brick/math, never floats).
 */
final class LineMath
{
    public static function money(BigDecimal|string|int|null $value): BigDecimal
    {
        return BigDecimal::of((string) ($value ?? '0'))->toScale(2, RoundingMode::HalfUp);
    }

    public static function qty(BigDecimal|string|int|null $value): BigDecimal
    {
        return Qty::of($value);
    }

    /**
     * qty × unit cost, or 0.00 while the cost is not known.
     */
    public static function lineTotal(BigDecimal|string|int $qty, ?string $unitCost): BigDecimal
    {
        if ($unitCost === null) {
            return BigDecimal::of('0.00');
        }

        return self::qty($qty)->multipliedBy($unitCost)->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * Base units in one of $unitId for this product (1 bag = 50 kg → "50.000").
     *
     * @throws ValidationException when the product is not bought/sold in that unit
     */
    public static function factor(?Product $product, int $unitId, string $field): string
    {
        $factor = $product?->units->firstWhere('unit_id', $unitId)?->factor;

        if ($factor === null) {
            throw ValidationException::withMessages([$field => 'This unit is not set up for '.($product->name ?? 'the product').'.']);
        }

        return $factor;
    }
}
