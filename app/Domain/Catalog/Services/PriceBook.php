<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\PriceList;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPrice;
use App\Domain\Inventory\Support\Qty;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads current prices. A price is current when it is the latest row (by effective_from,
 * then id) for its product × price list × unit × quantity tier that is already in
 * effect. A latest row with a null price means the price was removed.
 *
 * Tiers are keyed by min_qty as a 3-decimal string ("0.000", "1.000").
 */
class PriceBook
{
    /**
     * Current any-quantity prices (tier 0) for many products on one price list. No fallback.
     *
     * @param  list<int>  $productIds
     * @return array<int, array<int, string>> product_id => [unit_id => price]
     */
    public function forProducts(array $productIds, int $priceListId): array
    {
        $prices = [];

        foreach ($this->current($productIds, [$priceListId]) as $productId => $lists) {
            foreach ($lists[$priceListId] ?? [] as $unitId => $tiers) {
                if (isset($tiers['0.000'])) {
                    $prices[$productId][$unitId] = $tiers['0.000'];
                }
            }
        }

        return $prices;
    }

    /**
     * Current any-quantity prices (tier 0) of one product on every price list.
     *
     * @return array<int, array<int, string>> price_list_id => [unit_id => price]
     */
    public function forProduct(int $productId): array
    {
        $prices = [];

        foreach ($this->tiersForProduct($productId) as $listId => $units) {
            foreach ($units as $unitId => $tiers) {
                if (isset($tiers['0.000'])) {
                    $prices[$listId][$unitId] = $tiers['0.000'];
                }
            }
        }

        return $prices;
    }

    /**
     * Every current price of one product, tiers included.
     *
     * @return array<int, array<int, array<string, string>>> price_list_id => [unit_id => [min_qty => price]]
     */
    public function tiersForProduct(int $productId): array
    {
        return $this->current([$productId])[$productId] ?? [];
    }

    /**
     * Prices for selling on a price list, with the default (Retail) list as the fallback
     * for anything the list has no price for.
     *
     * @param  list<int>  $productIds
     */
    public function selling(array $productIds, int $priceListId): SellingPrices
    {
        $defaultListId = PriceList::default()?->id;
        $listIds = array_values(array_unique(array_filter([$priceListId, $defaultListId])));

        return new SellingPrices($this->current($productIds, $listIds), $priceListId, $defaultListId);
    }

    /**
     * Products with no current price on a price list (any unit, any quantity tier): they
     * cannot be billed on that list. A price removed later (a null row) counts as none.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function whereWithoutPrice(Builder $query, int $priceListId): Builder
    {
        $now = now();

        return $query->whereNotExists(fn ($prices) => $prices
            ->selectRaw('1')
            ->from('product_prices as pp')
            ->whereColumn('pp.product_id', 'products.id')
            ->where('pp.price_list_id', $priceListId)
            ->whereNull('pp.variant_id')
            ->whereNotNull('pp.price')
            ->where('pp.effective_from', '<=', $now)
            ->whereNotExists(fn ($newer) => $newer
                ->selectRaw('1')
                ->from('product_prices as newer')
                ->whereColumn('newer.product_id', 'pp.product_id')
                ->whereColumn('newer.price_list_id', 'pp.price_list_id')
                ->whereColumn('newer.unit_id', 'pp.unit_id')
                ->whereColumn('newer.min_qty', 'pp.min_qty')
                ->whereNull('newer.variant_id')
                ->where('newer.effective_from', '<=', $now)
                ->where(fn ($later) => $later
                    ->whereColumn('newer.effective_from', '>', 'pp.effective_from')
                    ->orWhere(fn ($same) => $same->whereColumn('newer.effective_from', 'pp.effective_from')->whereColumn('newer.id', '>', 'pp.id')))));
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<int>|null  $priceListIds
     * @return array<int, array<int, array<int, array<string, string>>>> product_id => [price_list_id => [unit_id => [min_qty => price]]], tiers ascending
     */
    private function current(array $productIds, ?array $priceListIds = null): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = ProductPrice::query()
            ->whereIn('product_id', $productIds)
            ->whereNull('variant_id')
            ->when($priceListIds !== null, fn ($query) => $query->whereIn('price_list_id', $priceListIds))
            ->where('effective_from', '<=', now())
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get()
            // Later rows overwrite earlier ones, leaving the current price per key.
            ->keyBy(fn (ProductPrice $price) => "{$price->product_id}:{$price->price_list_id}:{$price->unit_id}:".Qty::of($price->min_qty))
            ->filter(fn (ProductPrice $price) => $price->price !== null)
            ->sortBy(fn (ProductPrice $price) => (float) $price->min_qty);

        $prices = [];

        foreach ($rows as $row) {
            $prices[$row->product_id][$row->price_list_id][$row->unit_id][(string) Qty::of($row->min_qty)] = (string) $row->price;
        }

        return $prices;
    }
}
