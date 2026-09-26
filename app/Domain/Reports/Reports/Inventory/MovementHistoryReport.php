<?php

namespace App\Domain\Reports\Reports\Inventory;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Support\StockReference;
use App\Domain\Reports\Report;
use App\Domain\Reports\Support\Column;
use App\Domain\Reports\Support\Filter;
use App\Domain\Reports\Support\ReportGroup;
use App\Domain\Reports\Support\ReportInput;
use App\Domain\Reports\Support\ReportResult;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;

/**
 * The stock ledger for a period: every movement in and out.
 */
class MovementHistoryReport extends Report
{
    public const MAX_ROWS = 20000;

    public function key(): string
    {
        return 'stock-movements';
    }

    public function title(): string
    {
        return 'Stock movement history';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Inventory;
    }

    public function description(): string
    {
        return 'Every stock movement (goods received, sales, returns, adjustments …) with the document it came from.';
    }

    public function permission(): string
    {
        return 'reports.inventory';
    }

    public function defaultPeriod(): array
    {
        return [today()->subDays(6), today()];
    }

    public function filters(User $user): array
    {
        return [
            Filter::search('item', 'Item', 'Code or name'),
            Filter::select('type', 'Type', MovementType::options()),
        ];
    }

    public function columns(ReportInput $input): array
    {
        $cost = $input->user->can('viewCost', Product::class);

        return array_values(array_filter([
            Column::dateTime('at', 'Time'),
            Column::text('type', 'Type'),
            Column::text('code', 'Code'),
            Column::text('name', 'Item'),
            Column::text('lot', 'Lot'),
            Column::qty('qty', 'Qty'),
            Column::text('unit', 'Unit'),
            $cost ? Column::money('unit_cost', 'Unit cost', false) : null,
            $cost ? Column::money('value', 'Value') : null,
            Column::text('reference', 'Document'),
            Column::text('user', 'By'),
        ]));
    }

    public function run(ReportInput $input): ReportResult
    {
        $search = $input->get('item');

        $movements = StockMovement::query()
            ->with(['product' => fn ($query) => $query->withTrashed()->with('baseUnit'), 'batch', 'user', 'reference'])
            ->where('created_at', '>=', $input->from)
            ->where('created_at', '<', $input->end())
            ->when($input->get('type'), fn (Builder $query, $type) => $query->where('type', $type))
            ->when($search, fn (Builder $query) => $query->whereIn('product_id', Product::withTrashed()
                ->where(fn (Builder $inner) => $inner->where('short_code', $search)->orWhere('name', 'like', '%'.$search.'%'))
                ->select('id')))
            ->orderBy('id')
            ->limit(self::MAX_ROWS)
            ->get();

        $rows = $movements->map(function (StockMovement $movement): array {
            $reference = $movement->reference;

            return [
                'at' => $movement->created_at,
                'type' => $movement->type->label(),
                'code' => $movement->product?->short_code,
                'name' => $movement->product?->name,
                'lot' => $movement->batch->lot_no ?? '',
                'qty' => $movement->qty,
                'unit' => $movement->product?->baseUnit?->name,
                'unit_cost' => $movement->unit_cost,
                'value' => (string) BigDecimal::of($movement->qty)->multipliedBy($movement->unit_cost)->toScale(2, RoundingMode::HalfUp),
                'reference' => $reference instanceof StockReference ? $reference->referenceLabel() : ($movement->note ?? ''),
                'user' => $movement->user->name ?? '',
                '_url' => $reference instanceof StockReference ? $reference->referenceUrl() : null,
            ];
        })->all();

        return new ReportResult($rows, notes: count($rows) >= self::MAX_ROWS ? ['Only the first '.number_format(self::MAX_ROWS).' movements are shown. Choose a shorter period.'] : []);
    }
}
