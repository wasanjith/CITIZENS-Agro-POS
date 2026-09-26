<?php

namespace App\Domain\Reports\Reports\Inventory;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Services\StockAlerts;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Reports\Report;
use App\Domain\Reports\Services\ReportLookups;
use App\Domain\Reports\Support\Column;
use App\Domain\Reports\Support\Filter;
use App\Domain\Reports\Support\ReportGroup;
use App\Domain\Reports\Support\ReportInput;
use App\Domain\Reports\Support\ReportResult;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Items at or below their reorder level, with what is already on order.
 */
class ReorderListReport extends Report
{
    public function __construct(private readonly StockAlerts $alerts, private readonly ReportLookups $lookups) {}

    public function key(): string
    {
        return 'reorder-list';
    }

    public function title(): string
    {
        return 'Reorder list';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Inventory;
    }

    public function description(): string
    {
        return 'Items at or below their reorder level, what is already on order and who supplies them.';
    }

    public function permission(): array
    {
        return ['reports.inventory', 'purchasing.po.create'];
    }

    public function usesPeriod(): bool
    {
        return false;
    }

    public function filters(User $user): array
    {
        return [Filter::select('category', 'Category', $this->lookups->categories())];
    }

    public function columns(ReportInput $input): array
    {
        return [
            Column::text('code', 'Code'),
            Column::text('name', 'Item'),
            Column::qty('on_hand', 'On hand'),
            Column::qty('reorder_level', 'Reorder level'),
            Column::qty('on_order', 'On order'),
            Column::qty('suggested', 'Suggested order'),
            Column::text('unit', 'Unit'),
            Column::text('suppliers', 'Suppliers'),
        ];
    }

    public function run(ReportInput $input): ReportResult
    {
        $products = $this->alerts->whereLowStock(Product::query())
            ->with('baseUnit')
            ->addSelect(['products.*', 'on_hand' => StockAlerts::onHandSubquery()])
            ->when($input->get('category'), fn ($query, $category) => $query->whereIn('category_id', Category::find($category)?->descendantIdsAndSelf() ?? [0]))
            ->orderBy('short_code')
            ->get();

        $ids = $products->pluck('id');
        $onOrder = DB::table('po_lines as l')
            ->join('purchase_orders as o', 'o.id', '=', 'l.purchase_order_id')
            ->whereIn('o.status', [PurchaseOrderStatus::Submitted->value, PurchaseOrderStatus::Approved->value, PurchaseOrderStatus::Sent->value, PurchaseOrderStatus::Partial->value])
            ->whereIn('l.product_id', $ids)
            ->groupBy('l.product_id')
            ->selectRaw('l.product_id, SUM(GREATEST(l.base_qty - l.received_base_qty, 0)) AS qty')
            ->pluck('qty', 'product_id');
        $suppliers = DB::table('supplier_products as sp')
            ->join('suppliers as s', 's.id', '=', 'sp.supplier_id')
            ->whereIn('sp.product_id', $ids)
            ->whereNull('s.deleted_at')
            ->orderByDesc('sp.updated_at')
            ->get(['sp.product_id', 's.name'])
            ->groupBy('product_id');

        $rows = $products->map(function (Product $product) use ($onOrder, $suppliers): array {
            $ordered = BigDecimal::of((string) ($onOrder[$product->id] ?? '0'));
            $suggested = BigDecimal::of((string) $product->reorder_qty)->minus($ordered);

            return [
                'code' => $product->short_code,
                'name' => $product->name,
                'on_hand' => (string) $product->getAttribute('on_hand'),
                'reorder_level' => (string) $product->reorder_level,
                'on_order' => (string) $ordered,
                'suggested' => $suggested->isPositive() ? (string) $suggested : '0',
                'unit' => $product->baseUnit?->name,
                'suppliers' => ($suppliers[$product->id] ?? collect())->pluck('name')->take(3)->implode(', '),
                '_url' => route('catalog.products.show', $product->id),
                '_alert' => BigDecimal::of((string) $product->getAttribute('on_hand'))->isLessThanOrEqualTo(0),
            ];
        })->all();

        return new ReportResult($rows, ['Items to reorder' => number_format(count($rows))], ['Suggested order = the item\'s reorder quantity less what is already on order.']);
    }
}
