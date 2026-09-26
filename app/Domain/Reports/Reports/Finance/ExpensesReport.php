<?php

namespace App\Domain\Reports\Reports\Finance;

use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Reports\Report;
use App\Domain\Reports\Support\Column;
use App\Domain\Reports\Support\Filter;
use App\Domain\Reports\Support\ReportGroup;
use App\Domain\Reports\Support\ReportInput;
use App\Domain\Reports\Support\ReportResult;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Brick\Math\BigDecimal;

/**
 * Expenses in the period, one line each or totalled per expense head.
 */
class ExpensesReport extends Report
{
    public function key(): string
    {
        return 'expenses';
    }

    public function title(): string
    {
        return 'Expenses';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Finance;
    }

    public function description(): string
    {
        return 'Expenses paid from the drawer, cash at home or a bank, by expense head.';
    }

    public function permission(): string
    {
        return 'reports.finance';
    }

    public function filters(User $user): array
    {
        return [
            Filter::select('category', 'Expense head', ExpenseCategory::query()->orderBy('name')->pluck('name', 'id')->all()),
            Filter::select('view', 'Show', ['heads' => 'Totals per head', 'lines' => 'Each expense'], null, 'heads'),
        ];
    }

    public function columns(ReportInput $input): array
    {
        if ($input->get('view') === 'lines') {
            return [
                Column::date('date', 'Date'),
                Column::text('number', 'Number'),
                Column::text('head', 'Expense head'),
                Column::text('payee', 'Paid to'),
                Column::text('paid_from', 'Paid from'),
                Column::text('note', 'Note'),
                Column::money('amount', 'Amount'),
            ];
        }

        return [Column::text('head', 'Expense head'), Column::int('count', 'Expenses'), Column::money('amount', 'Amount'), Column::percent('share', 'Share')];
    }

    public function run(ReportInput $input): ReportResult
    {
        $expenses = Expense::query()
            ->with('category')
            ->whereNull('cancelled_at')
            ->whereBetween('date', [$input->from->toDateString(), $input->to->toDateString()])
            ->when($input->get('category'), fn ($query, $category) => $query->where('expense_category_id', (int) $category))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $total = $expenses->reduce(fn (BigDecimal $sum, Expense $expense) => $sum->plus($expense->amount), Money::zero());

        $rows = $input->get('view') === 'lines'
            ? $expenses->map(fn (Expense $expense) => [
                'date' => $expense->date,
                'number' => $expense->number,
                'head' => $expense->category->name,
                'payee' => $expense->payee ?? '',
                'paid_from' => $expense->paid_from->label(),
                'note' => $expense->note ?? '',
                'amount' => $expense->amount,
                '_url' => route('finance.expenses.show', $expense->id),
            ])->all()
            : $expenses->groupBy('expense_category_id')->map(function ($group) use ($total): array {
                $amount = $group->reduce(fn (BigDecimal $sum, Expense $expense) => $sum->plus($expense->amount), Money::zero());

                return ['head' => $group->first()->category->name, 'count' => $group->count(), 'amount' => (string) $amount, 'share' => (string) Money::percent($amount, $total)];
            })->sortByDesc(fn (array $row) => (float) $row['amount'])->values()->all();

        return new ReportResult($rows, ['Total expenses' => Money::format($total)], ['Cancelled expenses are left out.']);
    }
}
