<?php

namespace App\Domain\Reports\Reports\Sales;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Reports\Report;
use App\Domain\Reports\Services\DailySalesFigures;
use App\Domain\Reports\Services\ReportLookups;
use App\Domain\Reports\Services\SalesFacts;
use App\Domain\Reports\Support\Column;
use App\Domain\Reports\Support\Filter;
use App\Domain\Reports\Support\ReportGroup;
use App\Domain\Reports\Support\ReportInput;
use App\Domain\Reports\Support\ReportResult;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Sales (or gross profit) grouped by one thing: item, category, brand, counter, staff,
 * customer, hour of the day or day. Returns are taken off the group of the original sale.
 */
class SalesBreakdownReport extends Report
{
    public const DIMENSIONS = [
        'item' => 'item',
        'category' => 'category',
        'brand' => 'brand',
        'counter' => 'counter',
        'staff' => 'staff member',
        'customer' => 'customer',
        'hour' => 'hour of the day',
        'day' => 'day',
    ];

    /** Dimensions read from the invoice lines; the others from the bill totals. */
    private const LINE_DIMENSIONS = ['item', 'category', 'brand'];

    public function __construct(
        private readonly string $dimension,
        private readonly bool $profit,
        private readonly SalesFacts $facts,
        private readonly ReportLookups $lookups,
        private readonly DailySalesFigures $figures,
    ) {}

    public function key(): string
    {
        return ($this->profit ? 'profit-by-' : 'sales-by-').$this->dimension;
    }

    public function title(): string
    {
        return ($this->profit ? 'Gross profit by ' : 'Sales by ').self::DIMENSIONS[$this->dimension];
    }

    public function group(): ReportGroup
    {
        return $this->profit ? ReportGroup::Profit : ReportGroup::Sales;
    }

    public function description(): string
    {
        return $this->profit
            ? 'Net sales less the FIFO cost of the batches sold, by '.self::DIMENSIONS[$this->dimension].'.'
            : 'Sales, returns and discounts by '.self::DIMENSIONS[$this->dimension].'.';
    }

    public function permission(): string
    {
        return $this->profit ? 'reports.profit' : 'reports.sales';
    }

    public function filters(User $user): array
    {
        $filters = [];

        if (in_array($this->dimension, self::LINE_DIMENSIONS, true)) {
            $filters[] = Filter::select('category', 'Category', $this->lookups->categories());
        }

        if ($this->dimension !== 'counter') {
            $filters[] = Filter::select('terminal', 'Counter', $this->lookups->terminals());
        }

        return $filters;
    }

    public function columns(ReportInput $input): array
    {
        $columns = match ($this->dimension) {
            'item' => [Column::text('code', 'Code'), Column::text('name', 'Item'), Column::text('unit', 'Unit')],
            'customer' => [Column::text('code', 'Code'), Column::text('name', 'Customer')],
            'day' => [Column::date('name', 'Date')],
            default => [Column::text('name', ucfirst(self::DIMENSIONS[$this->dimension]))],
        };

        if ($this->profit) {
            return [
                ...$columns,
                ...($this->dimension === 'item' ? [Column::qty('qty', 'Net qty')] : []),
                Column::money('net_ex_tax', 'Net sales (excl. tax)'),
                Column::money('cost', 'Cost of sales'),
                Column::money('profit', 'Gross profit'),
                Column::percent('margin', 'Margin'),
            ];
        }

        return [
            ...$columns,
            ...($this->dimension === 'item'
                ? [Column::qty('qty_sold', 'Qty sold'), Column::qty('qty_returned', 'Qty returned')]
                : (in_array($this->dimension, self::LINE_DIMENSIONS, true) ? [] : [Column::int('invoices', 'Invoices')])),
            Column::money('sales', 'Sales'),
            Column::money('returns', 'Returns'),
            Column::money('net', 'Net sales'),
            Column::money('discount', 'Discounts'),
            ...(in_array($this->dimension, ['counter', 'staff', 'customer', 'hour'], true) ? [Column::money('average', 'Average bill', false)] : []),
            Column::percent('share', 'Share'),
        ];
    }

    public function run(ReportInput $input): ReportResult
    {
        $groups = $this->dimension === 'counter' && $this->figures->usesSummaries($input->from, $input->to)
            ? $this->countersFromSummaries($input)
            : $this->grouped($input)->get();

        $totalNet = $groups->reduce(fn (BigDecimal $sum, object $group) => $sum->plus((string) $group->net), BigDecimal::zero());
        $names = $this->names($groups->pluck('group_key')->all());

        $rows = $groups->map(function (object $group) use ($names, $totalNet): array {
            $net = Money::of((string) $group->net);
            $netExTax = $net->minus(Money::of((string) $group->tax));
            $cost = Money::of((string) $group->cost);
            $invoices = (int) $group->invoices;

            return [
                ...$names[$group->group_key] ?? ['name' => (string) $group->group_key],
                'invoices' => $invoices,
                'qty_sold' => (string) $group->qty_sold,
                'qty_returned' => (string) $group->qty_returned,
                'qty' => (string) $group->qty,
                'sales' => (string) Money::of((string) $group->sales),
                'returns' => (string) Money::of((string) $group->returns),
                'net' => (string) $net,
                'discount' => (string) Money::of((string) $group->discount),
                'average' => $invoices > 0 ? (string) Money::of((string) $group->sales)->dividedBy($invoices, 2, RoundingMode::HalfUp) : null,
                'share' => (string) Money::percent($net, $totalNet),
                'net_ex_tax' => (string) $netExTax,
                'cost' => (string) $cost,
                'profit' => (string) $netExTax->minus($cost),
                'margin' => (string) Money::percent($netExTax->minus($cost), $netExTax),
                '_sort' => in_array($this->dimension, ['hour', 'day'], true) ? (string) $group->group_key : $net,
            ];
        });

        $rows = in_array($this->dimension, ['hour', 'day'], true)
            ? $rows->sortBy('_sort')
            : ($this->profit ? $rows->sortByDesc(fn (array $row) => (float) $row['profit']) : $rows->sortByDesc(fn (array $row) => (float) $row['net']));

        $tiles = [];

        if ($this->profit) {
            $netExTax = $rows->reduce(fn (BigDecimal $sum, array $row) => $sum->plus($row['net_ex_tax']), Money::zero());
            $profit = $rows->reduce(fn (BigDecimal $sum, array $row) => $sum->plus($row['profit']), Money::zero());
            $tiles = ['Net sales' => Money::format($netExTax), 'Gross profit' => Money::format($profit), 'Margin' => number_format((float) (string) Money::percent($profit, $netExTax), 1).' %'];
        }

        $notes = [];

        if (in_array($this->dimension, self::LINE_DIMENSIONS, true)) {
            $notes[] = 'Line amounts include each line\'s share of the bill discount, so they can differ from the bill totals by a few cents.';
        }

        if ($this->dimension === 'item') {
            $notes[] = 'Quantities are in the item\'s base unit.';
        }

        return new ReportResult($rows->map(fn (array $row) => collect($row)->except('_sort')->all())->values()->all(), $tiles, $notes);
    }

    private function grouped(ReportInput $input): Builder
    {
        $lineLevel = in_array($this->dimension, self::LINE_DIMENSIONS, true);
        $facts = $lineLevel ? $this->facts->lines($input->from, $input->end()) : $this->facts->bills($input->from, $input->end());
        $key = match ($this->dimension) {
            'item' => 'f.product_id',
            'category' => 'f.category_id',
            'brand' => 'COALESCE(f.brand_id, 0)',
            'counter' => 'f.terminal_id',
            'staff' => 'f.user_id',
            'customer' => 'COALESCE(f.customer_id, 0)',
            'hour' => 'HOUR(f.at)',
            default => 'DATE(f.at)',
        };

        $query = DB::query()->fromSub($facts, 'f')
            ->groupByRaw($key)
            ->selectRaw("{$key} AS group_key, ".SalesFacts::sums());

        if (($terminal = $input->get('terminal')) !== null && $this->dimension !== 'counter') {
            $query->where('f.terminal_id', (int) $terminal);
        }

        if ($lineLevel && ($category = $input->get('category')) !== null) {
            $query->whereIn('f.category_id', Category::find($category)?->descendantIdsAndSelf() ?? [0]);
        }

        return $query;
    }

    /**
     * @return Collection<int, stdClass>
     */
    private function countersFromSummaries(ReportInput $input): Collection
    {
        return $this->figures->between($input->from, $input->to)->groupBy('terminal_id')
            ->map(fn (Collection $days, int $terminalId) => $this->counterGroup($days, $terminalId))
            ->values();
    }

    /**
     * @param  Collection<int, array<string, int|string>>  $days
     */
    private function counterGroup(Collection $days, int $terminalId): stdClass
    {
        $sum = fn (string $key) => (string) $days->reduce(fn (BigDecimal $total, array $day) => $total->plus((string) $day[$key]), BigDecimal::zero());

        $group = new stdClass;
        $group->group_key = $terminalId;
        $group->qty = $group->qty_sold = $group->qty_returned = 0;

        foreach (['invoices', 'gross', 'discount', 'sales', 'returns', 'net', 'tax', 'cost'] as $key) {
            $group->{$key} = $sum($key);
        }

        return $group;
    }

    /**
     * @param  list<int|string>  $keys
     * @return array<int|string, array<string, string>>
     */
    private function names(array $keys): array
    {
        return match ($this->dimension) {
            'item' => Product::withTrashed()->with('baseUnit')->whereIn('id', $keys)->get()
                ->mapWithKeys(fn (Product $product) => [$product->id => ['code' => $product->short_code, 'name' => $product->name, 'unit' => $product->baseUnit->name ?? '']])
                ->all(),
            'category' => collect($keys)->mapWithKeys(fn ($id) => [$id => ['name' => $this->lookups->categories()[(int) $id] ?? 'No category']])->all(),
            'brand' => collect($keys)->mapWithKeys(fn ($id) => [$id => ['name' => $this->lookups->brands()[(int) $id] ?? 'No brand']])->all(),
            'counter' => collect($keys)->mapWithKeys(fn ($id) => [$id => ['name' => $this->lookups->terminal((int) $id)]])->all(),
            'staff' => collect($keys)->mapWithKeys(fn ($id) => [$id => ['name' => $this->lookups->user((int) $id)]])->all(),
            'customer' => $this->customerNames($keys),
            'hour' => collect($keys)->mapWithKeys(fn ($hour) => [$hour => ['name' => sprintf('%02d:00–%02d:00', $hour, ((int) $hour + 1) % 24)]])->all(),
            default => [],
        };
    }

    /**
     * @param  list<int|string>  $keys
     * @return array<int|string, array<string, string>>
     */
    private function customerNames(array $keys): array
    {
        $customers = $this->lookups->customers(array_map('intval', $keys));

        return collect($keys)->mapWithKeys(fn ($id) => [$id => isset($customers[(int) $id])
            ? ['code' => $customers[(int) $id]->code, 'name' => $customers[(int) $id]->name]
            : ['code' => '', 'name' => 'Walk-in (no customer)']])->all();
    }
}
