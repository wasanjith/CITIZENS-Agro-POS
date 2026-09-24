<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPrice;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\PriceBook;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a product with its units, prices and variants in one transaction.
 *
 * Expected $data (already validated):
 *   product fields, plus
 *   units:    list of {unit_id, factor, is_default_sale, is_default_purchase}
 *   prices:   list of {unit_id, price_list_id, price}          (ignored without catalog.prices.manage)
 *   variants: list of {id?, short_code, name, sku, attributes, is_active}
 *   reference_cost / min_selling_margin_pct                    (ignored without catalog.cost.view)
 */
class SaveProductAction
{
    private const PRODUCT_FIELDS = [
        'short_code', 'sku', 'name', 'name_si', 'name_ta', 'aliases', 'description',
        'category_id', 'brand_id', 'base_unit_id', 'tax_id',
        'track_batches', 'track_expiry', 'reorder_level', 'reorder_qty', 'attributes', 'is_active',
    ];

    public function __construct(private readonly PriceBook $priceBook) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor, ?Product $product = null, bool $index = true): Product
    {
        $product ??= new Product(['created_by' => $actor->id]);

        return DB::transaction(function () use ($data, $actor, $product, $index): Product {
            $product->fill(Arr::only($data, self::PRODUCT_FIELDS));

            if ($actor->can('viewCost', Product::class)) {
                $product->fill(Arr::only($data, Product::COST_FIELDS));
            }

            if (array_key_exists('variants', $data)) {
                $product->has_variants = count($data['variants']) > 0;
            }

            $product->save();

            if (array_key_exists('units', $data)) {
                $this->syncUnits($product, $data['units']);
            }

            if (array_key_exists('variants', $data)) {
                $this->syncVariants($product, $data['variants']);
            }

            if (array_key_exists('prices', $data) && $actor->can('managePrices', Product::class)) {
                $this->savePrices($product, $data['prices'], $actor);
            }

            $product->refresh();

            // Re-index after units/variants changed (Scout indexes on save, before those exist).
            if ($index) {
                $product->searchable();
            }

            return $product;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $units
     */
    private function syncUnits(Product $product, array $units): void
    {
        $rows = collect($units)
            ->reject(fn (array $unit) => (int) $unit['unit_id'] === $product->base_unit_id)
            ->keyBy(fn (array $unit) => (int) $unit['unit_id'])
            ->map(fn (array $unit) => [
                'factor' => $unit['factor'],
                'is_default_sale' => (bool) ($unit['is_default_sale'] ?? false),
                'is_default_purchase' => (bool) ($unit['is_default_purchase'] ?? false),
            ]);

        // The base unit is always there with factor 1.
        $base = collect($units)->first(fn (array $unit) => (int) $unit['unit_id'] === $product->base_unit_id);
        $rows->put($product->base_unit_id, [
            'factor' => 1,
            'is_default_sale' => (bool) ($base['is_default_sale'] ?? false),
            'is_default_purchase' => (bool) ($base['is_default_purchase'] ?? false),
        ]);

        foreach (['is_default_sale', 'is_default_purchase'] as $flag) {
            $defaults = $rows->filter(fn (array $row) => $row[$flag])->keys();
            // Exactly one default: the first one ticked, or the base unit if none.
            $chosen = $defaults->first() ?? $product->base_unit_id;
            $rows = $rows->map(fn (array $row, int $unitId) => [...$row, $flag => $unitId === $chosen]);
        }

        $product->units()->whereNotIn('unit_id', $rows->keys())->delete();

        foreach ($rows as $unitId => $row) {
            ProductUnit::updateOrCreate(['product_id' => $product->id, 'unit_id' => $unitId], $row);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     */
    private function syncVariants(Product $product, array $variants): void
    {
        $keep = [];

        foreach ($variants as $data) {
            $variant = isset($data['id'])
                ? $product->variants()->whereKey($data['id'])->firstOrFail()
                : new ProductVariant(['product_id' => $product->id]);

            $variant->fill(Arr::only($data, ['short_code', 'sku', 'name', 'attributes', 'is_active']));
            $variant->save();
            $keep[] = $variant->id;
        }

        // Soft delete: old invoices may still point at a removed variant.
        $product->variants()->whereNotIn('id', $keep)->get()->each->delete();
    }

    /**
     * Insert a new price row only where the price actually changed, so product_prices
     * doubles as the price history.
     *
     * @param  list<array<string, mixed>>  $prices
     */
    private function savePrices(Product $product, array $prices, User $actor): void
    {
        $current = $this->priceBook->forProduct($product->id);
        $factors = $product->units()->pluck('factor', 'unit_id');
        $now = now();

        foreach ($prices as $index => $row) {
            if (($row['price'] ?? null) === null || $row['price'] === '' || ! $factors->has((int) $row['unit_id'])) {
                continue;
            }

            $price = BigDecimal::of((string) $row['price'])->toScale(2, RoundingMode::HalfUp);
            $existing = $current[(int) $row['price_list_id']][(int) $row['unit_id']] ?? null;

            $this->guardMinimumPrice($product, $price, (string) $factors[(int) $row['unit_id']], "prices.{$index}.price");

            if ($existing !== null && BigDecimal::of($existing)->isEqualTo($price)) {
                continue;
            }

            ProductPrice::create([
                'product_id' => $product->id,
                'unit_id' => (int) $row['unit_id'],
                'price_list_id' => (int) $row['price_list_id'],
                'price' => (string) $price,
                'effective_from' => $now,
                'created_by' => $actor->id,
            ]);
        }
    }

    /**
     * With a minimum margin set, a price may not go below cost × (1 + margin%).
     */
    private function guardMinimumPrice(Product $product, BigDecimal $price, string $factor, string $field): void
    {
        $minimum = self::minimumPrice($product, $factor);

        if ($minimum !== null && $price->isLessThan($minimum)) {
            throw ValidationException::withMessages([
                $field => "The price is below the minimum of Rs. {$minimum} (cost + {$product->min_selling_margin_pct}% margin).",
            ]);
        }
    }

    /**
     * Lowest allowed price for one unit (factor base units), or null when there is no guard.
     */
    public static function minimumPrice(Product $product, string $factor): ?BigDecimal
    {
        if ($product->reference_cost === null || $product->min_selling_margin_pct === null) {
            return null;
        }

        return BigDecimal::of($product->reference_cost)
            ->multipliedBy($factor)
            ->multipliedBy(BigDecimal::of($product->min_selling_margin_pct)->dividedBy(100, 4)->plus(1))
            ->toScale(2, RoundingMode::Up);
    }
}
