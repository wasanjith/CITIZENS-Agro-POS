<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\ProductPrice;
use Illuminate\Support\Collection;

/**
 * Reads current prices. A price is current when it is the latest row
 * (by effective_from, then id) that is already in effect.
 */
class PriceBook
{
    /**
     * Current product-level prices for many products on one price list.
     *
     * @param  list<int>  $productIds
     * @return array<int, array<int, string>> product_id => [unit_id => price]
     */
    public function forProducts(array $productIds, int $priceListId): array
    {
        if ($productIds === []) {
            return [];
        }

        $prices = [];

        foreach ($this->currentRows($productIds, [$priceListId]) as $row) {
            $prices[$row->product_id][$row->unit_id] = $row->price;
        }

        return $prices;
    }

    /**
     * Current prices of one product on every price list.
     *
     * @return array<int, array<int, string>> price_list_id => [unit_id => price]
     */
    public function forProduct(int $productId): array
    {
        $prices = [];

        foreach ($this->currentRows([$productId]) as $row) {
            $prices[$row->price_list_id][$row->unit_id] = $row->price;
        }

        return $prices;
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<int>|null  $priceListIds
     * @return Collection<string, ProductPrice>
     */
    private function currentRows(array $productIds, ?array $priceListIds = null): Collection
    {
        return ProductPrice::query()
            ->whereIn('product_id', $productIds)
            ->whereNull('variant_id')
            ->when($priceListIds !== null, fn ($query) => $query->whereIn('price_list_id', $priceListIds))
            ->where('effective_from', '<=', now())
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get()
            // Later rows overwrite earlier ones, leaving the current price per key.
            ->keyBy(fn (ProductPrice $price) => "{$price->product_id}:{$price->price_list_id}:{$price->unit_id}");
    }
}
