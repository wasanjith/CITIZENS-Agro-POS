<?php

namespace App\Domain\Reports\Reports\Customers;

use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Services\CustomerStatement;
use App\Domain\Reports\Report;
use App\Domain\Reports\Support\Column;
use App\Domain\Reports\Support\ReportGroup;
use App\Domain\Reports\Support\ReportInput;
use App\Domain\Reports\Support\ReportResult;
use App\Domain\Sales\Support\Money;

/**
 * Unpaid credit invoices per customer by days overdue, next to the account balance and limit.
 */
class ReceivablesAgeingReport extends Report
{
    public function __construct(private readonly CustomerStatement $statements) {}

    public function key(): string
    {
        return 'receivables-ageing';
    }

    public function title(): string
    {
        return 'Receivables ageing';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Customers;
    }

    public function description(): string
    {
        return 'What each credit customer owes, by how long it is overdue, with their credit limit.';
    }

    public function permission(): string
    {
        return 'customers.view';
    }

    public function usesPeriod(): bool
    {
        return false;
    }

    public function columns(ReportInput $input): array
    {
        return [
            Column::text('code', 'Code'),
            Column::text('name', 'Customer'),
            Column::text('phone', 'Phone'),
            ...collect(CustomerStatement::bucketLabels())->map(fn (string $label, string $key) => Column::money($key, $label))->values()->all(),
            Column::money('total', 'Unpaid invoices'),
            Column::money('balance', 'Account balance'),
            Column::money('limit', 'Credit limit', false),
        ];
    }

    public function run(ReportInput $input): ReportResult
    {
        $ageing = $this->statements->ageingAll()->keyBy('customer_id');
        $customers = Customer::withTrashed()->orderBy('name')->get()->filter(
            fn (Customer $customer) => $ageing->has($customer->id) || ! $customer->balance()->isZero()
        );

        $rows = $customers->map(function (Customer $customer) use ($ageing): array {
            $row = $ageing->get($customer->id);
            $balance = Money::of($customer->balance());

            return [
                'code' => $customer->code,
                'name' => $customer->name,
                'phone' => $customer->phone ?? '',
                ...($row['buckets'] ?? array_fill_keys(array_keys(CustomerStatement::BUCKETS), '0.00')),
                'total' => $row['total'] ?? '0.00',
                'balance' => (string) $balance,
                'limit' => $customer->credit_limit,
                '_url' => route('customers.show', $customer->id),
                '_alert' => Money::of($customer->credit_limit)->isPositive() && $balance->isGreaterThan($customer->credit_limit),
            ];
        })->sortByDesc(fn (array $row) => (float) $row['balance'])->values()->all();

        return new ReportResult($rows, notes: ['Red: over the credit limit. A negative balance is an advance paid by the customer.']);
    }
}
