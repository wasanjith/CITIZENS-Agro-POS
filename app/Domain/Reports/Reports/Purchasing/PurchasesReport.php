<?php

namespace App\Domain\Reports\Reports\Purchasing;

use App\Domain\Catalog\Models\Category;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
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
 * Goods received (posted GRNs) by supplier or by item, less returns to the supplier.
 */
class PurchasesReport extends Report
{
    public function __construct(private readonly bool $byItem, private readonly ReportLookups $lookups) {}

    public function key(): string
    {
        return $this->byItem ? 'purchases-by-item' : 'purchases-by-supplier';
    }

    public function title(): string
    {
        return $this->byItem ? 'Purchases by item' : 'Purchases by supplier';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Purchasing;
    }

    public function description(): string
    {
        return $this->byItem
            ? 'Quantity and value received per item (posted goods receipts), with the average buying cost.'
            : 'Goods received and returned per supplier.';
    }

    public function permission(): string
    {
        return 'purchasing.po.approve';
    }

    public function canBeViewedBy(User $user): bool
    {
        return $user->can('purchasing.po.approve') && $user->can('catalog.cost.view');
    }

    public function filters(User $user): array
    {
        return $this->byItem
            ? [Filter::select('supplier', 'Supplier', $this->lookups->suppliers()), Filter::select('category', 'Category', $this->lookups->categories())]
            : [];
    }

    public function columns(ReportInput $input): array
    {
        if ($this->byItem) {
            return [
                Column::text('code', 'Code'),
                Column::text('name', 'Item'),
                Column::qty('qty', 'Qty received'),
                Column::qty('free_qty', 'Free qty'),
                Column::text('unit', 'Unit'),
                Column::money('value', 'Value'),
                Column::money('avg_cost', 'Average cost', false),
                Column::int('receipts', 'Receipts', false),
            ];
        }

        return [
            Column::text('supplier', 'Supplier'),
            Column::int('receipts', 'Goods receipts'),
            Column::money('subtotal', 'Before discount'),
            Column::money('discount', 'Discount'),
            Column::money('total', 'Received'),
            Column::money('returns', 'Returned'),
            Column::money('net', 'Net purchases'),
            Column::percent('share', 'Share'),
        ];
    }

    public function run(ReportInput $input): ReportResult
    {
        return $this->byItem ? $this->byItem($input) : $this->bySupplier($input);
    }

    private function bySupplier(ReportInput $input): ReportResult
    {
        $receipts = DB::table('goods_receipts')
            ->where('status', GoodsReceiptStatus::Posted->value)
            ->where('received_at', '>=', $input->from)
            ->where('received_at', '<', $input->end())
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, COUNT(*) AS receipts, SUM(subtotal) AS subtotal, SUM(discount) AS discount, SUM(total) AS total')
            ->get()
            ->keyBy('supplier_id');
        $returns = DB::table('supplier_returns')
            ->whereBetween('return_date', [$input->from->toDateString(), $input->to->toDateString()])
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, SUM(total) AS total')
            ->pluck('total', 'supplier_id');

        $ids = $receipts->keys()->merge($returns->keys())->unique();
        $net = fn ($id) => Money::of((string) ($receipts[$id]->total ?? '0'))->minus(Money::of((string) ($returns[$id] ?? '0')));
        $grand = $ids->reduce(fn (BigDecimal $sum, $id) => $sum->plus($net($id)), Money::zero());

        $rows = $ids->map(fn ($id) => [
            'supplier' => $this->lookups->suppliers()[(int) $id] ?? "#{$id}",
            'receipts' => (int) ($receipts[$id]->receipts ?? 0),
            'subtotal' => (string) Money::of((string) ($receipts[$id]->subtotal ?? '0')),
            'discount' => (string) Money::of((string) ($receipts[$id]->discount ?? '0')),
            'total' => (string) Money::of((string) ($receipts[$id]->total ?? '0')),
            'returns' => (string) Money::of((string) ($returns[$id] ?? '0')),
            'net' => (string) $net($id),
            'share' => (string) Money::percent($net($id), $grand),
            '_url' => route('purchasing.suppliers.show', (int) $id),
        ])->sortByDesc(fn (array $row) => (float) $row['net'])->values()->all();

        return new ReportResult($rows, ['Net purchases' => Money::format($grand)]);
    }

    private function byItem(ReportInput $input): ReportResult
    {
        $rows = DB::table('grn_lines as l')
            ->join('goods_receipts as g', 'g.id', '=', 'l.goods_receipt_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->join('units as u', 'u.id', '=', 'p.base_unit_id')
            ->where('g.status', GoodsReceiptStatus::Posted->value)
            ->where('g.received_at', '>=', $input->from)
            ->where('g.received_at', '<', $input->end())
            ->when($input->get('supplier'), fn ($query, $supplier) => $query->where('g.supplier_id', (int) $supplier))
            ->when($input->get('category'), fn ($query, $category) => $query->whereIn('p.category_id', Category::find($category)?->descendantIdsAndSelf() ?? [0]))
            ->groupBy('p.id', 'p.short_code', 'p.name', 'u.name')
            ->selectRaw('p.id, p.short_code, p.name, u.name AS unit, SUM(l.base_qty) AS qty, SUM(l.free_qty * l.base_qty / NULLIF(l.qty, 0)) AS free_qty,
                SUM(l.line_total * (1 - COALESCE(g.discount / NULLIF(g.subtotal, 0), 0))) AS value, COUNT(DISTINCT g.id) AS receipts')
            ->orderByDesc('value')
            ->get()
            ->map(function (object $row): array {
                $units = BigDecimal::of((string) $row->qty)->plus((string) ($row->free_qty ?? '0'));

                return [
                    'code' => $row->short_code,
                    'name' => $row->name,
                    'qty' => $row->qty,
                    'free_qty' => (string) BigDecimal::of((string) ($row->free_qty ?? '0'))->toScale(3, RoundingMode::HalfUp),
                    'unit' => $row->unit,
                    'value' => (string) Money::of((string) $row->value),
                    'avg_cost' => $units->isPositive() ? (string) Money::of((string) $row->value)->dividedBy($units, 2, RoundingMode::HalfUp) : null,
                    'receipts' => (int) $row->receipts,
                    '_url' => route('catalog.products.show', $row->id),
                ];
            });

        return new ReportResult($rows->all(), notes: ['Quantities in the base unit. Value is after the receipt discount; average cost includes free goods.']);
    }
}
