<?php

namespace App\Domain\Reports\Reports\Audit;

use App\Domain\Catalog\Models\PriceList;
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
use Illuminate\Support\Facades\DB;

/**
 * Every selling price set in the period, with the price it replaced and who changed it.
 * Prices are never overwritten (a change adds a row), so the history is complete.
 */
class PriceChangesReport extends Report
{
    public function __construct(private readonly ReportLookups $lookups) {}

    public function key(): string
    {
        return 'price-changes';
    }

    public function title(): string
    {
        return 'Price change history';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Audit;
    }

    public function description(): string
    {
        return 'Every selling price changed in the period: old price, new price and who changed it.';
    }

    public function permission(): array
    {
        return ['admin.audit.view', 'catalog.prices.manage'];
    }

    public function filters(User $user): array
    {
        return [
            Filter::search('item', 'Item', 'Code or name'),
            Filter::select('list', 'Price list', PriceList::query()->orderBy('id')->pluck('name', 'id')->all()),
        ];
    }

    public function columns(ReportInput $input): array
    {
        return [
            Column::dateTime('at', 'Changed'),
            Column::text('code', 'Code'),
            Column::text('name', 'Item'),
            Column::text('unit', 'Unit'),
            Column::text('list', 'Price list'),
            Column::money('old', 'Old price', false),
            Column::money('new', 'New price', false),
            Column::percent('change', 'Change'),
            Column::text('user', 'Changed by'),
        ];
    }

    public function run(ReportInput $input): ReportResult
    {
        $search = $input->get('item');

        $rows = DB::table('product_prices as pp')
            ->join('products as p', 'p.id', '=', 'pp.product_id')
            ->join('units as u', 'u.id', '=', 'pp.unit_id')
            ->join('price_lists as l', 'l.id', '=', 'pp.price_list_id')
            ->where('pp.effective_from', '>=', $input->from)
            ->where('pp.effective_from', '<', $input->end())
            ->when($input->get('list'), fn ($query, $list) => $query->where('pp.price_list_id', (int) $list))
            ->when($search, fn ($query) => $query->where(fn ($inner) => $inner->where('p.short_code', $search)->orWhere('p.name', 'like', '%'.$search.'%')))
            ->orderBy('pp.effective_from')
            ->orderBy('pp.id')
            ->selectRaw('pp.id, pp.product_id, pp.effective_from, pp.price, pp.created_by, p.short_code, p.name, u.name AS unit, l.name AS list,
                (SELECT old.price FROM product_prices old
                  WHERE old.product_id = pp.product_id AND old.price_list_id = pp.price_list_id AND old.unit_id = pp.unit_id
                    AND old.variant_id <=> pp.variant_id AND (old.effective_from < pp.effective_from OR (old.effective_from = pp.effective_from AND old.id < pp.id))
                  ORDER BY old.effective_from DESC, old.id DESC LIMIT 1) AS old_price')
            ->get()
            ->map(fn (object $row) => [
                'at' => $row->effective_from,
                'code' => $row->short_code,
                'name' => $row->name,
                'unit' => $row->unit,
                'list' => $row->list,
                'old' => $row->old_price,
                'new' => $row->price,
                'change' => $row->old_price !== null ? (string) Money::percent(BigDecimal::of($row->price)->minus($row->old_price), Money::of($row->old_price)) : null,
                'user' => $this->lookups->user($row->created_by !== null ? (int) $row->created_by : null),
                '_url' => route('catalog.products.show', $row->product_id),
                '_alert' => $row->old_price !== null && BigDecimal::of($row->price)->isLessThan($row->old_price),
            ])
            ->all();

        return new ReportResult($rows, notes: ['An empty old price is the first price set for that item, unit and list. Red: the price went down.']);
    }
}
