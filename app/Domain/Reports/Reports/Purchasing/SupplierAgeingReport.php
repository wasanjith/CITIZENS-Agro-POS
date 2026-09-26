<?php

namespace App\Domain\Reports\Reports\Purchasing;

use App\Domain\Customers\Services\CustomerStatement;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Reports\Report;
use App\Domain\Reports\Support\Column;
use App\Domain\Reports\Support\ReportGroup;
use App\Domain\Reports\Support\ReportInput;
use App\Domain\Reports\Support\ReportResult;
use App\Domain\Sales\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * What the shop owes each supplier: unpaid goods receipts by how long they are overdue
 * (receipt date + the supplier's payment terms), next to the supplier's ledger balance.
 */
class SupplierAgeingReport extends Report
{
    public function key(): string
    {
        return 'supplier-ageing';
    }

    public function title(): string
    {
        return 'Supplier ageing';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Purchasing;
    }

    public function description(): string
    {
        return 'Unpaid goods receipts per supplier by days overdue, and each supplier\'s balance.';
    }

    public function permission(): array
    {
        return ['purchasing.suppliers.pay', 'reports.finance'];
    }

    public function usesPeriod(): bool
    {
        return false;
    }

    public function columns(ReportInput $input): array
    {
        return [
            Column::text('supplier', 'Supplier'),
            Column::int('terms', 'Terms (days)', false),
            ...collect(CustomerStatement::bucketLabels())->map(fn (string $label, string $key) => Column::money($key, $label))->values()->all(),
            Column::money('unpaid', 'Unpaid receipts'),
            Column::money('other', 'Opening / other'),
            Column::money('balance', 'Balance owed'),
        ];
    }

    public function run(ReportInput $input): ReportResult
    {
        $receipts = DB::table('goods_receipts as g')
            ->join('suppliers as s', 's.id', '=', 'g.supplier_id')
            ->where('g.status', GoodsReceiptStatus::Posted->value)
            ->whereRaw('g.total > g.amount_paid')
            ->selectRaw('g.supplier_id, g.total - g.amount_paid AS owed, DATEDIFF(CURDATE(), DATE_ADD(DATE(g.received_at), INTERVAL s.payment_terms_days DAY)) AS days_overdue')
            ->get()
            ->groupBy('supplier_id');

        $suppliers = Supplier::withTrashed()->orderBy('name')->get()->filter(
            fn (Supplier $supplier) => $receipts->has($supplier->id) || ! $supplier->balance()->isZero()
        );

        $rows = $suppliers->map(function (Supplier $supplier) use ($receipts): array {
            $buckets = array_fill_keys(array_keys(CustomerStatement::BUCKETS), Money::zero());
            $unpaid = Money::zero();

            foreach ($receipts->get($supplier->id, collect()) as $receipt) {
                $key = CustomerStatement::bucketFor((int) $receipt->days_overdue);
                $buckets[$key] = $buckets[$key]->plus((string) $receipt->owed);
                $unpaid = $unpaid->plus((string) $receipt->owed);
            }

            $balance = Money::of($supplier->balance());

            return [
                'supplier' => $supplier->name,
                'terms' => $supplier->payment_terms_days,
                ...array_map(fn (BigDecimal $amount) => (string) $amount, $buckets),
                'unpaid' => (string) $unpaid,
                'other' => (string) $balance->minus($unpaid),
                'balance' => (string) $balance,
                '_url' => route('purchasing.suppliers.show', $supplier->id),
                '_alert' => $buckets['over_90']->isPositive(),
            ];
        })->values()->all();

        return new ReportResult($rows, notes: ['"Opening / other" is the opening balance, advances paid and returns not yet set against a receipt.']);
    }
}
