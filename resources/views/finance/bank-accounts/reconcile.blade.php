@extends('layouts.app')

@php $money = fn ($value) => number_format((float) (string) $value, 2); @endphp

@section('title', 'Reconcile '.$bank->displayName())

@section('content')
    <x-ui.page-header :title="'Reconcile '.$bank->displayName()" description="Tick each line that appears on the bank statement. When the difference is 0.00 the bank book agrees with the bank.">
        <x-ui.button variant="secondary" :href="route('finance.bank-accounts.show', $bank)">Back</x-ui.button>
    </x-ui.page-header>

    @foreach (['transactions', 'statement_balance', 'statement_date'] as $field)
        @error($field)<x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>@enderror
    @endforeach

    <form method="GET" action="{{ route('finance.bank-accounts.reconcile', $bank) }}" class="mb-4 flex items-end gap-3">
        <x-ui.date-input name="statement_date" label="Statement date" :value="$statementDate->toDateString()" />
        <x-ui.button type="submit" variant="secondary">Show lines up to this date</x-ui.button>
    </form>

    <form method="POST" action="{{ route('finance.bank-accounts.reconcile.store', $bank) }}"
          x-data="{
              cleared: {{ (float) (string) $cleared }},
              statement: @js(old('statement_balance', '')),
              ticked: {},
              get tickedTotal() { return Object.values(this.ticked).reduce((sum, value) => sum + value, 0) },
              get difference() { return (parseFloat(this.statement) || 0) - (this.cleared + this.tickedTotal) },
              format(value) { return value.toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) },
          }">
        @csrf
        <input type="hidden" name="statement_date" value="{{ $statementDate->toDateString() }}">

        <div class="mb-4 grid gap-4 sm:grid-cols-4">
            <x-ui.money-input name="statement_balance" label="Balance on the statement" required x-model="statement" />
            <x-ui.stat-tile label="Already reconciled" :value="'Rs. '.$money($cleared)" />
            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <p class="text-sm text-gray-500">Ticked now</p>
                <p class="mt-1 text-xl font-semibold tabular" x-text="'Rs. ' + format(tickedTotal)"></p>
            </div>
            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <p class="text-sm text-gray-500">Difference</p>
                <p class="mt-1 text-xl font-semibold tabular" :class="Math.abs(difference) < 0.005 ? 'text-brand-700' : 'text-red-700'" x-text="'Rs. ' + format(difference)"></p>
            </div>
        </div>

        @if ($transactions->isEmpty())
            <x-ui.empty-state title="Nothing to tick" description="Every transaction up to this date is already reconciled." />
        @else
            <x-ui.table>
                <x-slot:head>
                    <th class="w-10"></th>
                    <th>Date</th>
                    <th>Description</th>
                    <th>Reference</th>
                    <th class="text-right">In</th>
                    <th class="text-right">Out</th>
                </x-slot:head>
                @foreach ($transactions as $transaction)
                    @php $signed = (float) (string) $transaction->signedAmount(); @endphp
                    <tr>
                        <td><input type="checkbox" name="transactions[]" value="{{ $transaction->id }}" class="rounded border-gray-300 text-brand-600" aria-label="On the statement" x-on:change="$event.target.checked ? ticked[{{ $transaction->id }}] = {{ $signed }} : delete ticked[{{ $transaction->id }}]"></td>
                        <td class="whitespace-nowrap text-gray-600">{{ $transaction->date->format('Y-m-d') }}</td>
                        <td>{{ $transaction->description }}</td>
                        <td class="font-mono text-xs">{{ $transaction->reference }}</td>
                        <td class="text-right tabular">{{ $signed > 0 ? $money($transaction->amount) : '' }}</td>
                        <td class="text-right tabular">{{ $signed < 0 ? $money($transaction->amount) : '' }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif

        <div class="mt-4 flex items-end justify-end gap-3">
            <x-ui.input name="note" label="Note" maxlength="255" class="w-72" />
            <x-ui.button type="submit">Save reconciliation</x-ui.button>
        </div>
    </form>

    @if ($history->isNotEmpty())
        <x-ui.card title="Earlier reconciliations" class="mt-6">
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach ($history as $reconciliation)
                    <li class="flex justify-between gap-3 py-2">
                        <span>{{ $reconciliation->statement_date->format('Y-m-d') }} · statement Rs. {{ $money($reconciliation->statement_balance) }}</span>
                        <span @class(['text-red-700' => (float) $reconciliation->difference != 0])>difference Rs. {{ $money($reconciliation->difference) }}</span>
                        <span class="text-gray-500">{{ $reconciliation->createdBy?->name }}</span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
@endsection
