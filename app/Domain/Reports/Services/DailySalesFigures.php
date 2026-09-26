<?php

namespace App\Domain\Reports\Services;

use App\Domain\Sales\Enums\PaymentMethod;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales per day and counter: bills, returns, voids and the money taken by payment method.
 * Worked out live from the sales tables, or read from `daily_sales_summaries` (rebuilt every
 * night) when a report covers more than 12 months.
 */
class DailySalesFigures
{
    /**
     * Ranges longer than this read the nightly summaries for past days.
     */
    public const SUMMARY_AFTER_DAYS = 366;

    public const AMOUNTS = ['gross', 'discount', 'sales', 'returns', 'net', 'tax', 'cost', 'void_value',
        'pay_cash', 'pay_card', 'pay_bank_transfer', 'pay_cheque', 'pay_credit', 'pay_split'];

    public const COUNTS = ['invoices', 'voids'];

    public function __construct(private readonly SalesFacts $facts) {}

    /**
     * @return Collection<int, array<string, int|string>> rows keyed date, terminal_id + figures
     */
    public function between(Carbon $from, Carbon $to): Collection
    {
        if ((int) $from->diffInDays($to) + 1 <= self::SUMMARY_AFTER_DAYS) {
            return $this->live($from, $to);
        }

        $lastSummaryDay = $to->copy()->min(today()->subDay());
        $rows = $this->fromSummaries($from, $lastSummaryDay);

        return $to->gte(today()) ? $rows->concat($this->live(today(), $to)) : $rows;
    }

    public function usesSummaries(Carbon $from, Carbon $to): bool
    {
        return (int) $from->diffInDays($to) + 1 > self::SUMMARY_AFTER_DAYS;
    }

    /**
     * @return Collection<int, array<string, int|string>>
     */
    public function live(Carbon $from, Carbon $to): Collection
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->addDay()->startOfDay();
        $rows = [];

        $bills = DB::query()->fromSub($this->facts->bills($start, $end), 'f')
            ->groupByRaw('DATE(f.at), f.terminal_id')
            ->selectRaw('DATE(f.at) AS date, f.terminal_id, '.SalesFacts::sums())
            ->get();

        foreach ($bills as $bill) {
            $row = &$this->row($rows, (string) $bill->date, (int) $bill->terminal_id);
            foreach (['invoices', 'gross', 'discount', 'sales', 'returns', 'net', 'tax', 'cost'] as $key) {
                $row[$key] = (string) $bill->{$key};
            }
            unset($row);
        }

        $voids = DB::table('sales')
            ->where('voided_at', '>=', $start)
            ->where('voided_at', '<', $end)
            ->groupByRaw('DATE(voided_at), invoiced_terminal_id')
            ->selectRaw('DATE(voided_at) AS date, invoiced_terminal_id AS terminal_id, COUNT(*) AS voids, SUM(total) AS void_value')
            ->get();

        foreach ($voids as $void) {
            $row = &$this->row($rows, (string) $void->date, (int) $void->terminal_id);
            $row['voids'] = (string) $void->voids;
            $row['void_value'] = (string) $void->void_value;
            unset($row);
        }

        $payments = DB::table('payments as p')
            ->join('sales as s', 's.id', '=', 'p.sale_id')
            ->where('p.created_at', '>=', $start)
            ->where('p.created_at', '<', $end)
            ->groupByRaw('DATE(p.created_at), s.invoiced_terminal_id, p.method')
            ->selectRaw('DATE(p.created_at) AS date, s.invoiced_terminal_id AS terminal_id, p.method, SUM(p.amount) AS amount')
            ->get();

        foreach ($payments as $payment) {
            $key = 'pay_'.$payment->method;

            if (! in_array($key, self::AMOUNTS, true)) {
                continue;
            }

            $row = &$this->row($rows, (string) $payment->date, (int) $payment->terminal_id);
            $row[$key] = (string) BigDecimal::of($row[$key])->plus((string) $payment->amount);
            unset($row);
        }

        ksort($rows);

        return collect(array_values($rows));
    }

    /**
     * @return Collection<int, array<string, int|string>>
     */
    public function fromSummaries(Carbon $from, Carbon $to): Collection
    {
        return DB::table('daily_sales_summaries')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->orderBy('terminal_id')
            ->get()
            ->map(fn (object $summary) => $this->summaryRow($summary));
    }

    /**
     * @return array<string, int|string>
     */
    private function summaryRow(object $summary): array
    {
        $row = ['date' => (string) $summary->date, 'terminal_id' => (int) $summary->terminal_id];

        foreach ([...self::COUNTS, ...self::AMOUNTS] as $key) {
            $row[$key] = (string) $summary->{$key};
        }

        return $row;
    }

    /**
     * Rebuild the stored summaries of these days from the sales tables.
     *
     * @return int rows written
     */
    public function rebuild(Carbon $from, Carbon $to): int
    {
        $rows = $this->live($from, $to);

        DB::transaction(function () use ($from, $to, $rows): void {
            DB::table('daily_sales_summaries')->whereBetween('date', [$from->toDateString(), $to->toDateString()])->delete();

            foreach ($rows->chunk(500) as $chunk) {
                DB::table('daily_sales_summaries')->insert($chunk->map(fn (array $row) => [...$row, 'built_at' => now()])->values()->all());
            }
        });

        return $rows->count();
    }

    /**
     * Money taken per payment method: column key => label.
     *
     * @return array<string, string>
     */
    public static function paymentColumns(): array
    {
        return collect(PaymentMethod::cases())
            ->mapWithKeys(fn (PaymentMethod $method) => ['pay_'.$method->value => $method->label()])
            ->all();
    }

    /**
     * @param  array<string, array<string, int|string>>  $rows
     * @return array<string, int|string>
     */
    private function &row(array &$rows, string $date, int $terminalId): array
    {
        $key = $date.'|'.str_pad((string) $terminalId, 6, '0', STR_PAD_LEFT);

        if (! isset($rows[$key])) {
            $rows[$key] = [
                'date' => $date,
                'terminal_id' => $terminalId,
                ...array_fill_keys(self::COUNTS, '0'),
                ...array_fill_keys(self::AMOUNTS, '0.00'),
            ];
        }

        return $rows[$key];
    }
}
