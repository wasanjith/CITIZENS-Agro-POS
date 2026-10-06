<?php

namespace App\Domain\Catalog\Import;

use App\Domain\Catalog\Models\PriceList;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Catalog\Services\PriceBook;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * The product list as .xlsx, in the import template's column layout, plus Variants,
 * Active and (with cost) Min Margin % at the end. The Cost column is left out for users
 * who may not see cost.
 */
class ProductsExport implements FromArray, ShouldAutoSize, WithHeadings
{
    use Exportable;

    /**
     * @param  Builder<Product>  $query
     */
    public function __construct(private readonly Builder $query, private readonly bool $withCost) {}

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        $headings = array_map(fn (string $key) => ProductImportColumns::all()[$key]['heading'], $this->keys());

        return $this->withCost ? [...$headings, 'Variants', 'Active', 'Min Margin %'] : [...$headings, 'Variants', 'Active'];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        $products = $this->query->with(['category.parent.parent', 'brand', 'baseUnit', 'units.unit', 'variants', 'opensInto'])->get();
        $lists = PriceList::query()->pluck('id', 'name');
        $retailId = $lists['Retail'] ?? null;
        $wholesaleId = $lists['Wholesale'] ?? null;
        $book = app(PriceBook::class);

        return $products->map(function (Product $product) use ($book, $retailId, $wholesaleId): array {
            $tiers = $book->tiersForProduct($product->id);
            $saleUnitId = $product->sold_loose ? $product->base_unit_id : $product->defaultSaleUnit()?->unit_id;
            $retail = $retailId !== null && $saleUnitId !== null ? ($tiers[$retailId][$saleUnitId] ?? []) : [];
            $wholesale = $wholesaleId !== null && $saleUnitId !== null ? ($tiers[$wholesaleId][$saleUnitId] ?? []) : [];

            $values = [
                'short_code' => $product->short_code,
                'name' => $product->name,
                'name_si' => $product->name_si,
                'name_ta' => $product->name_ta,
                'aliases' => $product->aliases,
                'category' => $product->category?->path(),
                'brand' => $product->brand?->name,
                'base_unit' => $product->baseUnit?->name,
                'pack_size' => null,
                'default_sale_unit' => $product->defaultSaleUnit()?->unit->name,
                'opening_stock' => null,
                'cost' => $product->reference_cost,
                'wholesale_price' => $wholesale['0.000'] ?? null,
                'retail_price' => $product->sold_loose ? null : ($retail['0.000'] ?? null),
                'loose_price_kg' => $product->sold_loose ? ($retail['1.000'] ?? $retail['0.000'] ?? null) : null,
                'loose_price_small' => $product->sold_loose && isset($retail['1.000']) ? ($retail['0.000'] ?? null) : null,
                'reorder_level' => $product->reorder_level,
                'reorder_qty' => $product->reorder_qty,
                'sale_units' => $product->units
                    ->reject(fn (ProductUnit $unit) => $unit->unit_id === $product->base_unit_id)
                    ->map(fn (ProductUnit $unit) => $unit->unit->name.'='.rtrim(rtrim($unit->factor, '0'), '.'))
                    ->implode('; '),
                'batch_no' => null,
                'expiry_date' => null,
                'opens_into' => $product->opensInto?->short_code,
                'opens_into_qty' => $product->opens_into_qty,
            ];

            $row = [
                ...array_map(fn (string $key) => $values[$key], $this->keys()),
                $product->variants->map(fn ($variant) => "{$variant->short_code} {$variant->name}")->implode('; '),
                $product->is_active ? 'yes' : 'no',
            ];

            return $this->withCost ? [...$row, $product->min_selling_margin_pct] : $row;
        })->all();
    }

    /**
     * Template columns in this export (no Cost without permission).
     *
     * @return list<string>
     */
    private function keys(): array
    {
        return array_values(array_filter(ProductImportColumns::keys(), fn (string $key) => $this->withCost || $key !== 'cost'));
    }
}
