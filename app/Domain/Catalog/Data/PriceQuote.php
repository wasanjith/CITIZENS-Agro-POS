<?php

namespace App\Domain\Catalog\Data;

/**
 * The price a line is sold at: the price of one unit, the list it came from and the
 * quantity tier it belongs to ("0.000" = any quantity, "1.000" = from 1 base unit).
 */
final readonly class PriceQuote
{
    public function __construct(
        public string $price,
        public int $priceListId,
        public string $minQty,
    ) {}
}
