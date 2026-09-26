@extends('layouts.app')

@php $money = fn ($value) => number_format((float) (string) $value, 2); @endphp

@section('title', $bank->displayName())

@section('content')
    <x-ui.page-header :title="$bank->displayName()" :description="$bank->account_name.' · '.$bank->type->label().($bank->is_active ? '' : ' · inactive')">
        <x-ui.button variant="secondary" :href="route('finance.money.create', ['to' => 'bank:'.$bank->id])">Deposit / transfer</x-ui.button>
        <x-ui.button variant="secondary" x-data x-on:click="$dispatch('open-modal', 'bank-charge')">Charge / interest</x-ui.button>
        <x-ui.button variant="secondary" :href="route('finance.bank-accounts.reconcile', $bank)">Reconcile</x-ui.button>
        <x-ui.button :href="route('finance.bank-accounts.edit', $bank)">Edit</x-ui.button>
    </x-ui.page-header>

    @foreach (['type', 'amount', 'date'] as $field)
        @error($field)<x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>@enderror
    @endforeach

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-ui.stat-tile label="Balance (bank book)" :value="'Rs. '.$money($current)" />
        <x-ui.stat-tile label="Not yet reconciled" :value="$unreconciled" hint="Transactions not ticked against a statement" />
        <x-ui.stat-tile label="Last reconciled" :value="$lastReconciliation?->statement_date->format('Y-m-d') ?? 'Never'" :hint="$lastReconciliation ? 'Difference Rs. '.$money($lastReconciliation->difference) : null" />
    </div>

    @include('finance.partials.period', ['action' => route('finance.bank-accounts.show', $bank)])

    <x-ui.table>
        <x-slot:head>
            <th>Date</th>
            <th>Description</th>
            <th>Reference</th>
            <th class="text-right">In</th>
            <th class="text-right">Out</th>
            <th class="text-right">Balance</th>
            <th></th>
        </x-slot:head>
        <tr class="bg-gray-50">
            <td class="text-gray-600">{{ $from->format('Y-m-d') }}</td>
            <td colspan="4" class="font-medium">Balance brought forward</td>
            <td class="text-right tabular font-medium">{{ $money($opening) }}</td>
            <td></td>
        </tr>
        @forelse ($rows as $row)
            @php $transaction = $row['transaction']; @endphp
            <tr>
                <td class="whitespace-nowrap text-gray-600">{{ $transaction->date->format('Y-m-d') }}</td>
                <td>{{ $transaction->description }} <span class="text-xs text-gray-500">· {{ $transaction->type->label() }}</span></td>
                <td class="font-mono text-xs">{{ $transaction->reference }}</td>
                <td class="text-right tabular">{{ $transaction->type->sign() > 0 ? $money($transaction->amount) : '' }}</td>
                <td class="text-right tabular">{{ $transaction->type->sign() < 0 ? $money($transaction->amount) : '' }}</td>
                <td class="text-right tabular">{{ $money($row['balance']) }}</td>
                <td>@if ($transaction->reconciled_at)<span title="Reconciled {{ $transaction->reconciled_at->format('Y-m-d') }}" class="text-brand-700">✓</span>@endif</td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center text-gray-500">No transactions in this period.</td></tr>
        @endforelse
        <tr class="bg-gray-50">
            <td class="text-gray-600">{{ $to->format('Y-m-d') }}</td>
            <td colspan="4" class="font-medium">Balance</td>
            <td class="text-right tabular font-semibold">{{ $money($closing) }}</td>
            <td></td>
        </tr>
    </x-ui.table>

    <x-ui.modal name="bank-charge" title="Bank charge or interest" max-width="md">
        <form method="POST" action="{{ route('finance.bank-accounts.charge', $bank) }}" id="bank-charge-form" class="grid gap-4">
            @csrf
            <x-ui.select name="type" label="Type" :options="['charge' => 'Bank charge (money out)', 'interest' => 'Interest (money in)']" required />
            <x-ui.money-input name="amount" label="Amount (Rs.)" required />
            <x-ui.date-input name="date" label="Date" :value="today()->toDateString()" required />
            <x-ui.input name="description" label="Description" maxlength="200" placeholder="SMS alert fee" />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'bank-charge')">Cancel</x-ui.button>
            <x-ui.button type="submit" form="bank-charge-form">Record</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
@endsection
