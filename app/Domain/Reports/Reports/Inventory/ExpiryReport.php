<?php

namespace App\Domain\Reports\Reports\Inventory;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Services\StockAlerts;
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

/**
 * Batches with stock that have expired or expire within N days.
 */
class ExpiryReport extends Report
{
    public function __construct(private readonly StockAlerts $alerts) {}

    public function key(): string
    {
        return 'expiry';
    }

    public function title(): string
    {
        return 'Expiry';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Inventory;
    }

    public function description(): string
    {
        return 'Batches with stock that have expired or expire within the chosen number of days.';
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
        return [Filter::number('days', 'Expiring within (days)', 90)];
    }

    public function columns(ReportInput $input): array
    {
        $cost = $input->user->can('viewCost', Product::class);

        return [
            Column::date('expiry', 'Expiry'),
            Column::int('days_left', 'Days left', false),
            Column::text('code', 'Code'),
            Column::text('name', 'Item'),
            Column::text('lot', 'Lot'),
            Column::qty('qty', 'On hand'),
            Column::text('unit', 'Unit'),
            ...($cost ? [Column::money('value', 'Value at cost')] : []),
        ];
    }

    public function run(ReportInput $input): ReportResult
    {
        $rows = $this->alerts->expiringLevels(min(3650, $input->int('days', 90)))
            ->with(['batch', 'product' => fn ($query) => $query->withTrashed()->with('baseUnit'), 'variant'])
            ->orderBy('batches.expiry_date')
            ->get()
            ->map(function (StockLevel $level): array {
                $daysLeft = (int) today()->diffInDays($level->batch->expiry_date, false);

                return [
                    'expiry' => $level->batch->expiry_date,
                    'days_left' => $daysLeft,
                    'code' => $level->product->short_code,
                    'name' => $level->product->name.($level->variant ? ' – '.$level->variant->name : ''),
                    'lot' => $level->batch->lot_no ?? '',
                    'qty' => $level->qty_on_hand,
                    'unit' => $level->product->baseUnit?->name,
                    'value' => (string) BigDecimal::of($level->qty_on_hand)->multipliedBy($level->batch->unit_cost)->toScale(2, RoundingMode::HalfUp),
                    '_alert' => $daysLeft < 0,
                ];
            });

        $expired = $rows->where('_alert', true);

        return new ReportResult($rows->all(), array_filter([
            'Batches' => number_format($rows->count()),
            'Already expired' => number_format($expired->count()),
            'Expired value' => $input->user->can('viewCost', Product::class)
                ? Money::format($expired->reduce(fn (BigDecimal $sum, array $row) => $sum->plus($row['value']), Money::zero()))
                : null,
        ]));
    }
}
