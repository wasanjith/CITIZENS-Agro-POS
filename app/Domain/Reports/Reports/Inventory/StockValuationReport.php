<?php

namespace App\Domain\Reports\Reports\Inventory;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Reports\Report;
use App\Domain\Reports\Services\ReportLookups;
use App\Domain\Reports\Support\Column;
use App\Domain\Reports\Support\Filter;
use App\Domain\Reports\Support\ReportGroup;
use App\Domain\Reports\Support\ReportInput;
use App\Domain\Reports\Support\ReportResult;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Stock on hand per product (or per batch) with its value at batch cost.
 */
class StockValuationReport extends Report
{
    public function __construct(private readonly bool $byBatch, private readonly ReportLookups $lookups) {}

    public function key(): string
    {
        return $this->byBatch ? 'stock-by-batch' : 'stock-valuation';
    }

    public function title(): string
    {
        return $this->byBatch ? 'Stock by batch' : 'Stock on hand & valuation';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Inventory;
    }

    public function description(): string
    {
        return $this->byBatch
            ? 'Every batch with stock: lot, expiry, quantity and cost.'
            : 'Quantity on hand, reserved by waiting invoices and value at cost, per product.';
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
            Filter::checkbox('zero', 'Include items with no stock'),
        ];
    }

    public function columns(ReportInput $input): array
    {
        $cost = $input->user->can('viewCost', Product::class);

        return array_values(array_filter([
            Column::text('code', 'Code'),
            Column::text('name', 'Item'),
            $this->byBatch ? null : Column::text('category', 'Category'),
            $this->byBatch ? Column::text('lot', 'Lot / batch') : null,
            $this->byBatch ? Column::date('expiry', 'Expiry') : null,
            $this->byBatch ? Column::date('received', 'Received') : null,
            Column::text('unit', 'Unit'),
            Column::qty('on_hand', 'On hand'),
            Column::qty('reserved', 'Reserved'),
            Column::qty('available', 'Available'),
            $cost ? Column::money('unit_cost', $this->byBatch ? 'Unit cost' : 'Average cost', false) : null,
            $cost ? Column::money('value', 'Value') : null,
        ]));
    }

    public function run(ReportInput $input): ReportResult
    {
        $query = DB::table('stock_levels as sl')
            ->join('batches as b', 'b.id', '=', 'sl.batch_id')
            ->join('products as p', 'p.id', '=', 'sl.product_id')
            ->leftJoin('product_variants as v', 'v.id', '=', 'sl.variant_id')
            ->join('units as u', 'u.id', '=', 'p.base_unit_id')
            ->when($input->get('category'), fn ($query, $category) => $query->whereIn('p.category_id', Category::find($category)?->descendantIdsAndSelf() ?? [0]));

        if ($this->byBatch) {
            $rows = $query->where('sl.qty_on_hand', $input->flag('zero') ? '>=' : '>', 0)
                ->orderBy('p.short_code')->orderBy('b.expiry_date')->orderBy('b.received_at')
                ->get(['p.short_code', 'p.name', 'v.name as variant', 'u.name as unit', 'b.lot_no', 'b.expiry_date', 'b.received_at', 'b.default_key', 'b.unit_cost', 'sl.qty_on_hand', 'sl.qty_reserved'])
                ->map(fn (object $row) => [
                    'code' => $row->short_code,
                    'name' => $row->name.($row->variant ? ' – '.$row->variant : ''),
                    'lot' => $row->lot_no ?? ($row->default_key !== null ? 'General stock' : ''),
                    'expiry' => $row->expiry_date,
                    'received' => $row->received_at,
                    'unit' => $row->unit,
                    'on_hand' => $row->qty_on_hand,
                    'reserved' => $row->qty_reserved,
                    'available' => (string) BigDecimal::of($row->qty_on_hand)->minus($row->qty_reserved),
                    'unit_cost' => $row->unit_cost,
                    'value' => (string) BigDecimal::of($row->qty_on_hand)->multipliedBy($row->unit_cost)->toScale(2, RoundingMode::HalfUp),
                    '_alert' => $row->expiry_date !== null && $row->expiry_date < today()->toDateString(),
                ]);
        } else {
            $rows = $query->groupBy('p.id', 'p.short_code', 'p.name', 'p.category_id', 'u.name')
                ->when(! $input->flag('zero'), fn ($query) => $query->havingRaw('SUM(sl.qty_on_hand) > 0'))
                ->orderBy('p.short_code')
                ->selectRaw('p.id, p.short_code, p.name, p.category_id, u.name AS unit, SUM(sl.qty_on_hand) AS on_hand, SUM(sl.qty_reserved) AS reserved, SUM(sl.qty_on_hand * b.unit_cost) AS value')
                ->get()
                ->map(fn (object $row) => [
                    'code' => $row->short_code,
                    'name' => $row->name,
                    'category' => $this->lookups->categories()[(int) $row->category_id] ?? '',
                    'unit' => $row->unit,
                    'on_hand' => $row->on_hand,
                    'reserved' => $row->reserved,
                    'available' => (string) BigDecimal::of($row->on_hand)->minus($row->reserved),
                    'unit_cost' => BigDecimal::of($row->on_hand)->isPositive() ? (string) BigDecimal::of($row->value)->dividedBy($row->on_hand, 2, RoundingMode::HalfUp) : null,
                    'value' => (string) Money::of((string) $row->value),
                    '_url' => route('catalog.products.show', $row->id),
                    '_alert' => BigDecimal::of($row->on_hand)->isNegative(),
                ]);
        }

        $tiles = ['Items' => number_format($rows->count())];

        if ($input->user->can('viewCost', Product::class)) {
            $tiles['Stock value'] = Money::format($rows->reduce(fn (BigDecimal $sum, array $row) => $sum->plus($row['value']), Money::zero()));
        }

        return new ReportResult($rows->values()->all(), $tiles, ['Quantities are in each item\'s base unit. Reserved = held by invoices waiting for settlement.']);
    }
}
