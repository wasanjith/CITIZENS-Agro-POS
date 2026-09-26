@extends('layouts.app')

@section('title', 'Expenses')

@section('content')
    <x-ui.page-header title="Expenses" description="Rent, electricity, transport and other running costs. Petty cash comes out of the drawer.">
        @can('manageCategories', \App\Domain\Finance\Models\Expense::class)
            <x-ui.button variant="secondary" :href="route('finance.expense-categories.index')">Expense heads</x-ui.button>
        @endcan
        <x-ui.button :href="route('finance.expenses.create')">Record expense</x-ui.button>
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('finance.expenses.index')" placeholder="Number, payee or note" class="mb-4">
        <x-ui.select name="filter[category]" :options="$categories" :value="request('filter.category')" placeholder="Any head" />
        <x-ui.select name="filter[paid_from]" :options="$sources" :value="request('filter.paid_from')" placeholder="Paid from anywhere" />
        <x-ui.date-input name="filter[from]" :value="request('filter.from')" />
        <x-ui.date-input name="filter[to]" :value="request('filter.to')" />
    </x-ui.filter-bar>

    @if ($expenses->isEmpty())
        <x-ui.empty-state title="No expenses found" />
    @else
        <x-ui.table>
            <x-slot:head>
                <x-ui.th-sortable column="number">Number</x-ui.th-sortable>
                <x-ui.th-sortable column="date" default="date">Date</x-ui.th-sortable>
                <th>Head</th>
                <th>Payee / note</th>
                <th>Paid from</th>
                <th>By</th>
                <x-ui.th-sortable column="amount" class="text-right">Amount</x-ui.th-sortable>
            </x-slot:head>
            @foreach ($expenses as $expense)
                <tr @class(['text-gray-400 line-through' => $expense->isCancelled()])>
                    <td><a href="{{ route('finance.expenses.show', $expense) }}" class="font-mono text-brand-700 hover:underline">{{ $expense->number }}</a></td>
                    <td class="whitespace-nowrap">{{ $expense->date->format('Y-m-d') }}</td>
                    <td>{{ $expense->category->name }}</td>
                    <td class="text-gray-600">{{ $expense->payee ?: $expense->note }} @if ($expense->receipt_path)<span title="Bill attached">📎</span>@endif</td>
                    <td>{{ $expense->paid_from->label() }}</td>
                    <td class="text-gray-600">{{ $expense->createdBy->name }}</td>
                    <td class="text-right tabular">{{ number_format((float) $expense->amount, 2) }}</td>
                </tr>
            @endforeach
            <tr class="bg-gray-50 font-semibold">
                <td colspan="6">Total (not cancelled, all pages)</td>
                <td class="text-right tabular">{{ number_format((float) $total, 2) }}</td>
            </tr>
        </x-ui.table>
        <x-ui.pagination :paginator="$expenses" />
    @endif
@endsection
