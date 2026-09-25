<?php

namespace App\Domain\Inventory\Data;

/**
 * Quantity taken from (or reserved in) one batch, with the batch cost per base unit.
 */
final readonly class StockAllocation
{
    public function __construct(
        public int $batchId,
        public string $qty,
        public string $unitCost,
    ) {}
}
