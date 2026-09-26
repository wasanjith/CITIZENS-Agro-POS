@extends('layouts.app')

@section('title', 'Cheques')

@section('content')
    <x-ui.page-header title="Cheques" description="Cheques from customers (taken at the cashier) and cheques given to suppliers (Supplier payments).">
        <x-ui.button variant="secondary" :href="route('finance.cheques.calendar')">Calendar</x-ui.button>
    </x-ui.page-header>

    <x-ui.tabs class="mb-4" :active="$direction->value" :tabs="[
        'received' => ['label' => 'Received', 'href' => route('finance.cheques.index', ['direction' => 'received', 'filter' => ['status' => 'open']])],
        'issued' => ['label' => 'Issued', 'href' => route('finance.cheques.index', ['direction' => 'issued', 'filter' => ['status' => 'open']])],
    ]" />

    <x-ui.filter-bar :action="route('finance.cheques.index')" placeholder="Cheque no., bank or name" class="mb-4">
        <input type="hidden" name="direction" value="{{ $direction->value }}">
        <x-ui.select name="filter[status]" :options="$statuses" :value="request('filter.status')" placeholder="Any status" />
    </x-ui.filter-bar>

    @if ($cheques->isEmpty())
        <x-ui.empty-state title="No cheques found" />
    @else
        <x-ui.table>
            <x-slot:head>
                <x-ui.th-sortable column="cheque_date" default="cheque_date">Cheque date</x-ui.th-sortable>
                <th>Number</th>
                <th>{{ $direction === \App\Domain\Finance\Enums\ChequeDirection::Received ? 'From' : 'To' }}</th>
                <th>Bank</th>
                <th>Status</th>
                <x-ui.th-sortable column="amount" class="text-right">Amount</x-ui.th-sortable>
            </x-slot:head>
            @foreach ($cheques as $cheque)
                <tr>
                    <td @class(['whitespace-nowrap', 'font-medium text-amber-800' => $cheque->status->isOpen() && $cheque->cheque_date->lte(today())])>
                        {{ $cheque->cheque_date->format('Y-m-d') }}
                        @if ($cheque->isPostDated())<span class="text-xs text-gray-500">post-dated</span>@endif
                    </td>
                    <td><a href="{{ route('finance.cheques.show', $cheque) }}" class="font-mono text-brand-700 hover:underline">{{ $cheque->number }}</a></td>
                    <td>{{ $cheque->partyLabel() }}</td>
                    <td class="text-gray-600">{{ $cheque->bank_name ?: '—' }}@if ($cheque->bankAccount) <span class="text-xs">→ {{ $cheque->bankAccount->displayName() }}</span>@endif</td>
                    <td><x-ui.badge :color="$cheque->status->color()">{{ $cheque->status->label() }}</x-ui.badge></td>
                    <td class="text-right tabular">{{ number_format((float) $cheque->amount, 2) }}</td>
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$cheques" />
    @endif
@endsection
