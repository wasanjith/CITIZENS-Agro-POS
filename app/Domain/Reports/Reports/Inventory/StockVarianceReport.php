<?php

namespace App\Domain\Reports\Reports\Inventory;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\AdjustmentStatus;
use App\Domain\Inventory\Enums\StocktakeStatus;
use App\Domain\Reports\Report;
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
 * Stock gained and lost through approved adjustments and posted stocktakes.
 */
class StockVarianceReport extends Report
{
    public function key(): string
    {
        return 'stock-variance';
    }

    public function title(): string
    {
        return 'Adjustment & stocktake variance';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Inventory;
    }

    public function description(): string
    {
        return 'Stock added or written off by approved adjustments and posted stocktakes, line by line.';
    }

    public function permission(): string
    {
        return 'reports.inventory';
    }

    public function filters(User $user): array
    {
        return [Filter::select('source', 'Source', ['adjustment' => 'Adjustments', 'stocktake' => 'Stocktakes'])];
    }

    public function columns(ReportInput $input): array
    {
        $cost = $input->user->can('viewCost', Product::class);

        return [
            Column::dateTime('at', 'Date'),
            Column::text('document', 'Document'),
            Column::text('reason', 'Reason'),
            Column::text('code', 'Code'),
            Column::text('name', 'Item'),
            Column::qty('qty', 'Difference'),
            Column::text('unit', 'Unit'),
            ...($cost ? [Column::money('value', 'Value')] : []),
        ];
    }

    public function run(ReportInput $input): ReportResult
    {
        $source = $input->get('source');
        $adjustments = DB::table('stock_adjustment_lines as l')
            ->join('stock_adjustments as a', 'a.id', '=', 'l.adjustment_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->join('units as u', 'u.id', '=', 'p.base_unit_id')
            ->where('a.status', AdjustmentStatus::Approved->value)
            ->where('a.approved_at', '>=', $input->from)
            ->where('a.approved_at', '<', $input->end())
            ->selectRaw("a.id AS document_id, 'adjustment' AS source, a.approved_at AS at, a.number, a.reason, p.short_code, p.name, u.name AS unit, l.qty, l.unit_cost");

        $stocktakes = DB::table('stocktake_lines as l')
            ->join('stocktakes as t', 't.id', '=', 'l.stocktake_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->join('units as u', 'u.id', '=', 'p.base_unit_id')
            ->where('t.status', StocktakeStatus::Posted->value)
            ->whereNotNull('l.counted_qty')
            ->whereColumn('l.counted_qty', '<>', 'l.system_qty')
            ->where('t.posted_at', '>=', $input->from)
            ->where('t.posted_at', '<', $input->end())
            ->selectRaw("t.id AS document_id, 'stocktake' AS source, t.posted_at AS at, t.number, 'stocktake' AS reason, p.short_code, p.name, u.name AS unit, l.counted_qty - l.system_qty AS qty, l.unit_cost");

        $query = match ($source) {
            'adjustment' => $adjustments,
            'stocktake' => $stocktakes,
            default => $adjustments->unionAll($stocktakes),
        };

        $rows = DB::query()->fromSub($query, 'v')->orderBy('at')->orderBy('number')->get()->map(fn (object $row) => [
            'at' => $row->at,
            'document' => $row->number,
            'reason' => ucfirst(str_replace('_', ' ', (string) $row->reason)),
            'code' => $row->short_code,
            'name' => $row->name,
            'qty' => $row->qty,
            'unit' => $row->unit,
            'value' => (string) BigDecimal::of((string) $row->qty)->multipliedBy((string) $row->unit_cost)->toScale(2, RoundingMode::HalfUp),
            '_url' => $row->source === 'adjustment' ? route('inventory.adjustments.show', $row->document_id) : route('inventory.stocktakes.show', $row->document_id),
            '_alert' => BigDecimal::of((string) $row->qty)->isNegative(),
        ]);

        $gain = $rows->reduce(fn (BigDecimal $sum, array $row) => BigDecimal::of($row['value'])->isPositive() ? $sum->plus($row['value']) : $sum, Money::zero());
        $loss = $rows->reduce(fn (BigDecimal $sum, array $row) => BigDecimal::of($row['value'])->isNegative() ? $sum->minus($row['value']) : $sum, Money::zero());

        return new ReportResult($rows->all(), $input->user->can('viewCost', Product::class)
            ? ['Stock gained' => Money::format($gain), 'Stock lost' => Money::format($loss), 'Net' => Money::format($gain->minus($loss))]
            : []);
    }
}
