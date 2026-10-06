<?php

namespace App\Domain\Reports\Reports\Inventory;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\PriceList;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\PriceBook;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Reports\Report;
use App\Domain\Reports\Services\ReportLookups;
use App\Domain\Reports\Support\Column;
use App\Domain\Reports\Support\Filter;
use App\Domain\Reports\Support\ReportGroup;
use App\Domain\Reports\Support\ReportInput;
use App\Domain\Reports\Support\ReportResult;
use App\Models\User;

/**
 * Active products with no selling price: the counter cannot bill them. Prices are
 * optional on import, so this is the list to work through after an import.
 */
class ProductsWithoutPriceReport extends Report
{
    public function __construct(
        private readonly ReportLookups $lookups,
        private readonly StockService $stock,
        private readonly PriceBook $prices,
    ) {}

    public function key(): string
    {
        return 'products-without-price';
    }

    public function title(): string
    {
        return 'Products without a selling price';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Inventory;
    }

    public function description(): string
    {
        return 'Active products the counter cannot bill because they have no selling price yet.';
    }

    public function permission(): string
    {
        return 'reports.inventory';
    }

    public function usesPeriod(): bool
    {
        return false;
    }

    public function filters(User $user): array
    {
        return [
            Filter::select('category', 'Category', $this->lookups->categories()),
        ];
    }

    public function columns(ReportInput $input): array
    {
        return [
            Column::text('code', 'Code'),
            Column::text('name', 'Item'),
            Column::text('category', 'Category'),
            Column::qty('on_hand', 'On hand'),
            Column::text('unit', 'Unit'),
            ...($input->user->can('viewCost', Product::class) ? [Column::money('cost', 'Cost / unit')] : []),
        ];
    }

    public function run(ReportInput $input): ReportResult
    {
        $retailId = PriceList::default()?->id;

        if ($retailId === null) {
            return new ReportResult([], ['Items' => '0'], ['There is no default price list.']);
        }

        $products = $this->prices->whereWithoutPrice(Product::query(), $retailId)
            ->with('baseUnit')
            ->active()
            ->when($input->get('category'), fn ($query, $category) => $query->whereIn('category_id', Category::find($category)?->descendantIdsAndSelf() ?? [0]))
            ->orderBy('short_code')
            ->get();

        $onHand = $this->stock->onHandByProduct($products->pluck('id')->all());

        $rows = $products->map(fn (Product $product) => [
            'code' => $product->short_code,
            'name' => $product->name,
            'category' => $this->lookups->categories()[$product->category_id] ?? '',
            'on_hand' => $onHand[$product->id] ?? '0.000',
            'unit' => $product->baseUnit?->name,
            'cost' => $product->reference_cost,
            '_url' => route('catalog.products.edit', $product),
        ]);

        return new ReportResult(
            $rows->values()->all(),
            ['Items' => number_format($rows->count())],
            ['Open an item to set its Selling Price (Units & Prices tab). Wholesale customers pay the selling price when there is no wholesale price.'],
        );
    }
}
