<?php

namespace App\Domain\Customers\Services;

use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Models\CustomerLedgerEntry;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Statement of account (ledger rows with a running balance) and ageing of unpaid credit
 * invoices by how long they are overdue.
 */
class CustomerStatement
{
    /**
     * Ageing buckets: label => [from days overdue, to days overdue].
     *
     * @var array<string, array{0: int, 1: int|null}>
     */
    public const BUCKETS = [
        'current' => [PHP_INT_MIN, 0],
        '1_30' => [1, 30],
        '31_60' => [31, 60],
        '61_90' => [61, 90],
        'over_90' => [91, null],
    ];

    /**
     * @return array<string, string>
     */
    public static function bucketLabels(): array
    {
        return ['current' => 'Not due', '1_30' => '1–30 days', '31_60' => '31–60 days', '61_90' => '61–90 days', 'over_90' => 'Over 90 days'];
    }

    /**
     * @return array{customer: Customer, from: CarbonInterface, to: CarbonInterface, opening: string, rows: list<array<string, mixed>>, total_debit: string, total_credit: string, closing: string, open_invoices: Collection<int, Sale>, ageing: array<string, string>}
     */
    public function build(Customer $customer, CarbonInterface $from, CarbonInterface $to): array
    {
        $before = CustomerLedgerEntry::query()
            ->where('customer_id', $customer->id)
            ->where('date', '<', $from->toDateString())
            ->selectRaw('COALESCE(SUM(debit), 0) AS debit, COALESCE(SUM(credit), 0) AS credit')
            ->toBase()
            ->first();

        $running = Money::of((string) ($before->debit ?? '0'))->minus(Money::of((string) ($before->credit ?? '0')));
        $opening = $running;
        $debits = Money::zero();
        $credits = Money::zero();
        $rows = [];

        $entries = CustomerLedgerEntry::query()
            ->where('customer_id', $customer->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        foreach ($entries as $entry) {
            $running = $running->plus($entry->debit)->minus($entry->credit);
            $debits = $debits->plus($entry->debit);
            $credits = $credits->plus($entry->credit);
            $rows[] = [
                'date' => $entry->date,
                'type' => $entry->type,
                'reference' => $entry->reference,
                'note' => $entry->note,
                'due_date' => $entry->due_date,
                'debit' => (string) Money::of($entry->debit),
                'credit' => (string) Money::of($entry->credit),
                'balance' => (string) $running,
            ];
        }

        $open = $customer->openCreditSales()->get();

        return [
            'customer' => $customer,
            'from' => $from,
            'to' => $to,
            'opening' => (string) $opening,
            'rows' => $rows,
            'total_debit' => (string) $debits,
            'total_credit' => (string) $credits,
            'closing' => (string) $running,
            'open_invoices' => $open,
            'ageing' => $this->ageing($open),
        ];
    }

    /**
     * Unpaid amounts per ageing bucket.
     *
     * @param  Collection<int, Sale>  $sales
     * @return array<string, string>
     */
    public function ageing(Collection $sales): array
    {
        $buckets = array_fill_keys(array_keys(self::BUCKETS), Money::zero());

        foreach ($sales as $sale) {
            $key = self::bucketFor($sale->due_date !== null ? (int) $sale->due_date->diffInDays(today(), false) : 0);
            $buckets[$key] = $buckets[$key]->plus($sale->balance_due);
        }

        return array_map(fn (BigDecimal $amount) => (string) $amount, $buckets);
    }

    public static function bucketFor(int $daysOverdue): string
    {
        foreach (self::BUCKETS as $key => [$min, $max]) {
            if ($daysOverdue >= $min && ($max === null || $daysOverdue <= $max)) {
                return $key;
            }
        }

        return 'current';
    }

    /**
     * Ageing of every customer with unpaid credit invoices (for the ageing report).
     *
     * @return Collection<int, array{customer_id: int, buckets: non-empty-array<string, string>, total: string}>
     */
    public function ageingAll(): Collection
    {
        $rows = Sale::query()
            ->whereIn('status', Customer::OPEN_SALE_STATUSES)
            ->where('balance_due', '>', 0)
            ->whereNotNull('customer_id')
            ->select(['customer_id', 'balance_due', DB::raw('DATEDIFF(CURDATE(), due_date) AS days_overdue')])
            ->toBase()
            ->get()
            ->groupBy('customer_id');

        return $rows->map(function (Collection $sales, int|string $customerId): array {
            $buckets = array_fill_keys(array_keys(self::BUCKETS), Money::zero());
            $total = Money::zero();

            foreach ($sales as $sale) {
                $key = self::bucketFor((int) ($sale->days_overdue ?? 0));
                $buckets[$key] = $buckets[$key]->plus((string) $sale->balance_due);
                $total = $total->plus((string) $sale->balance_due);
            }

            return [
                'customer_id' => (int) $customerId,
                'buckets' => array_map(fn (BigDecimal $amount) => (string) $amount, $buckets),
                'total' => (string) $total,
            ];
        })->values();
    }
}
