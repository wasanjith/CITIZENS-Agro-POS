<?php

namespace App\Domain\Reports\Reports\Inventory;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\MovementType;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Items in stock that have not sold for N days (or never).
 */
class DeadStockReport extends Report
{
    public function __construct(private readonly ReportLookups $lookups) {}

    public function key(): string
    {
        return 'dead-stock';
    }

    public function title(): string
    {
        return 'Dead stock';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Inventory;
    }

    public function description(): string
    {
        return 'Items with stock that have not sold in the chosen number of days.';
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
            Filter::number('days', 'No sale in (days)', 90),
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
            Column::date('last_sold', 'Last sold'),
            Column::int('days', 'Days since', false),
            ...($input->user->can('viewCost', Product::class) ? [Column::money('value', 'Value at cost')] : []),
        ];
    }

    public function run(ReportInput $input): ReportResult
    {
        $cutoff = today()->subDays($input->int('days', 90));
        $lastSale = DB::table('stock_movements')->where('type', MovementType::Sale->value)->groupBy('product_id')->selectRaw('product_id, MAX(created_at) AS last_sold');

        $rows = DB::table('products as p')
            ->join('stock_levels as sl', 'sl.product_id', '=', 'p.id')
            ->join('batches as b', 'b.id', '=', 'sl.batch_id')
            ->join('units as u', 'u.id', '=', 'p.base_unit_id')
            ->leftJoinSub($lastSale, 'ls', 'ls.product_id', '=', 'p.id')
            ->whereNull('p.deleted_at')
            ->where(fn ($query) => $query->whereNull('ls.last_sold')->orWhere('ls.last_sold', '<', $cutoff))
            ->when($input->get('category'), fn ($query, $category) => $query->whereIn('p.category_id', Category::find($category)?->descendantIdsAndSelf() ?? [0]))
            ->groupBy('p.id', 'p.short_code', 'p.name', 'p.category_id', 'u.name', 'ls.last_sold')
            ->havingRaw('SUM(sl.qty_on_hand) > 0')
            ->orderByRaw('ls.last_sold IS NOT NULL, ls.last_sold')
            ->selectRaw('p.id, p.short_code, p.name, p.category_id, u.name AS unit, ls.last_sold, SUM(sl.qty_on_hand) AS on_hand, SUM(sl.qty_on_hand * b.unit_cost) AS value')
            ->get()
            ->map(fn (object $row) => [
                'code' => $row->short_code,
                'name' => $row->name,
                'category' => $this->lookups->categories()[(int) $row->category_id] ?? '',
                'on_hand' => $row->on_hand,
                'unit' => $row->unit,
                'last_sold' => $row->last_sold,
                'days' => $row->last_sold !== null ? (int) Carbon::parse($row->last_sold)->startOfDay()->diffInDays(today()) : null,
                'value' => (string) Money::of((string) $row->value),
                '_url' => route('catalog.products.show', $row->id),
            ]);

        $tiles = ['Items' => number_format($rows->count())];

        if ($input->user->can('viewCost', Product::class)) {
            $tiles['Value tied up'] = Money::format($rows->reduce(fn (BigDecimal $sum, array $row) => $sum->plus($row['value']), Money::zero()));
        }

        return new ReportResult($rows->all(), $tiles, ['"Last sold" is empty for items never sold on the system.']);
    }
}
