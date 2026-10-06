<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Data\PriceQuote;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Support\Qty;
use Brick\Math\BigDecimal;

/**
 * Current selling prices for one price list (from PriceBook::selling()).
 *
 * A price comes from the requested list when it has one, else from the default list:
 * a wholesale customer pays the retail price for anything without a wholesale price.
 * Quantity tiers only count for products sold loose; others use the any-quantity price.
 */
final class SellingPrices
{
    /**
     * @param  array<int, array<int, array<int, array<string, string>>>>  $prices  product_id => [price_list_id => [unit_id => [min_qty => price]]]
     */
    public function __construct(
        private readonly array $prices,
        private readonly int $priceListId,
        private readonly ?int $defaultListId,
    ) {}

    /**
     * Price of one unit for a line of $baseQty base units, or null when neither list has one.
     */
    public function quote(Product $product, int $unitId, BigDecimal|string $baseQty): ?PriceQuote
    {
        $qty = Qty::of($baseQty);

        foreach ($this->lists() as $listId) {
            $match = null;

            foreach ($this->prices[$product->id][$listId][$unitId] ?? [] as $minQty => $price) {
                if ($minQty !== '0.000' && ! $product->sold_loose) {
                    continue;
                }

                if (Qty::of($minQty)->isLessThanOrEqualTo($qty)) {
                    $match = new PriceQuote($price, $listId, $minQty);
                }
            }

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * Any-quantity price of each unit, with the same fallback. Used to show prices in
     * search results and the unit picker.
     *
     * @return array<int, string> unit_id => price
     */
    public function startingPrices(int $productId): array
    {
        $prices = [];

        foreach (array_reverse($this->lists()) as $listId) {
            foreach ($this->prices[$productId][$listId] ?? [] as $unitId => $tiers) {
                if (isset($tiers['0.000'])) {
                    $prices[$unitId] = $tiers['0.000'];
                }
            }
        }

        return $prices;
    }

    /**
     * The rates of one unit by quantity, with the fallback already applied, so the
     * counter screen can price a line the way quote() will: the rate of the highest
     * min_qty at or below the line's base quantity.
     *
     * @return list<array{min_qty: string, price: string}>
     */
    public function tiers(Product $product, int $unitId): array
    {
        $breakpoints = [];

        foreach ($this->lists() as $listId) {
            foreach (array_keys($this->prices[$product->id][$listId][$unitId] ?? []) as $minQty) {
                if ($minQty === '0.000' || $product->sold_loose) {
                    $breakpoints[$minQty] = true;
                }
            }
        }

        $breakpoints = array_keys($breakpoints);
        usort($breakpoints, fn (string $a, string $b) => BigDecimal::of($a)->compareTo($b));
        $tiers = [];

        foreach ($breakpoints as $minQty) {
            $quote = $this->quote($product, $unitId, (string) $minQty);

            if ($quote !== null && ($tiers === [] || end($tiers)['price'] !== $quote->price)) {
                $tiers[] = ['min_qty' => (string) $minQty, 'price' => $quote->price];
            }
        }

        return $tiers;
    }

    /**
     * Lists to look in, in order: the requested one, then the default one.
     *
     * @return list<int>
     */
    private function lists(): array
    {
        return array_values(array_unique(array_filter([$this->priceListId, $this->defaultListId])));
    }
}
