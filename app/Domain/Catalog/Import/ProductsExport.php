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
 * The product list as .xlsx, in the import template's column layout.
 * Cost columns are only included for users allowed to see cost.
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
        $headings = ['short_code', 'name', 'name_si', 'name_ta', 'aliases', 'category', 'brand', 'base_unit', 'sale_units',
            'default_sale_unit', 'retail_price', 'wholesale_price', 'reorder_level', 'reorder_qty', 'variants', 'active'];

        return $this->withCost ? [...$headings, 'cost', 'min_margin_pct'] : $headings;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        $products = $this->query->with(['category.parent.parent', 'brand', 'baseUnit', 'units.unit', 'variants'])->get();
        $lists = PriceList::query()->pluck('id', 'name');
        $book = app(PriceBook::class);
        $ids = $products->pluck('id')->all();
        $retail = $lists->has('Retail') ? $book->forProducts($ids, $lists['Retail']) : [];
        $wholesale = $lists->has('Wholesale') ? $book->forProducts($ids, $lists['Wholesale']) : [];

        return $products->map(function (Product $product) use ($retail, $wholesale): array {
            $saleUnit = $product->defaultSaleUnit();
            $saleUnitId = $saleUnit?->unit_id;

            $row = [
                $product->short_code,
                $product->name,
                $product->name_si,
                $product->name_ta,
                $product->aliases,
                $product->category?->path(),
                $product->brand?->name,
                $product->baseUnit?->name,
                $product->units
                    ->reject(fn (ProductUnit $unit) => $unit->unit_id === $product->base_unit_id)
                    ->map(fn (ProductUnit $unit) => $unit->unit->name.'='.rtrim(rtrim($unit->factor, '0'), '.'))
                    ->implode('; '),
                $saleUnit?->unit->name,
                $saleUnitId ? ($retail[$product->id][$saleUnitId] ?? null) : null,
                $saleUnitId ? ($wholesale[$product->id][$saleUnitId] ?? null) : null,
                $product->reorder_level,
                $product->reorder_qty,
                $product->variants->map(fn ($variant) => "{$variant->short_code} {$variant->name}")->implode('; '),
                $product->is_active ? 'yes' : 'no',
            ];

            return $this->withCost ? [...$row, $product->reference_cost, $product->min_selling_margin_pct] : $row;
        })->all();
    }
}
