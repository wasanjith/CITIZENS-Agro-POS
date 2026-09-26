@extends('layouts.app')

@php $money = fn ($value) => number_format((float) (string) $value, 2); @endphp

@section('title', 'Pay a supplier')

@section('content')
    <x-ui.page-header title="Pay a supplier" description="Cash from the drawer or from home, a bank transfer, or a cheque." />

    @foreach (['allocations', 'amount', 'paid_from', 'bank_account_id', 'cheque_number'] as $field)
        @error($field)<x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>@enderror
    @endforeach

    <form method="GET" action="{{ route('purchasing.supplier-payments.create') }}" class="mb-6 max-w-xl" x-data x-on:search-select-changed="$nextTick(() => $el.submit())">
        <x-ui.search-select name="supplier" label="Supplier" :url="route('api.purchasing.suppliers')" :value="$supplier?->id" :value-label="$supplier?->name ?? ''" placeholder="Supplier name" />
    </form>

    @if ($supplier)
        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <x-ui.stat-tile label="Owed to {{ $supplier->name }}" :value="'Rs. '.$money($balance)" />
            <x-ui.stat-tile label="Unpaid goods receipts" :value="$openReceipts->count()" :hint="'Rs. '.$money($openReceipts->sum(fn ($receipt) => (float) (string) $receipt->outstanding()))" />
            <x-ui.stat-tile label="Terms" :value="$supplier->payment_terms_days ? $supplier->payment_terms_days.' days' : 'Cash'" />
        </div>

        <form method="POST" action="{{ route('purchasing.supplier-payments.store', $supplier) }}"
              x-data="{ mode: @js(old('allocation_mode', 'fifo')), from: @js(old('paid_from', 'bank')), busy: false }" @submit="busy = true">
            @csrf
            <x-ui.card>
                @if (! $drawerOpen)
                    <p class="mb-3 text-xs text-gray-500">No drawer is open at the main cashier, so cash from the drawer is not possible right now.</p>
                @endif
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.money-input name="amount" label="Amount (Rs.)" required autofocus />
                    <x-ui.select name="paid_from" label="Paid by" :options="$sources" required x-model="from" />
                    <x-ui.date-input name="date" label="Date" :value="old('date', today()->toDateString())" required />
                    <div x-show="from === 'bank' || from === 'cheque'" x-cloak>
                        <x-ui.select name="bank_account_id" label="Bank account" :options="$banks" placeholder="Choose…" />
                    </div>
                    <div x-show="from === 'cheque'" x-cloak>
                        <x-ui.input name="cheque_number" label="Cheque number" maxlength="30" />
                    </div>
                    <div x-show="from === 'cheque'" x-cloak>
                        <x-ui.date-input name="cheque_date" label="Cheque date" :value="old('cheque_date')" hint="A later date makes it a post-dated cheque." />
                    </div>
                    <x-ui.input name="reference" label="Reference" maxlength="100" hint="Transfer or receipt number." />
                    <x-ui.input name="note" label="Note" maxlength="255" class="sm:col-span-2" />
                </div>

                @if ($openReceipts->isNotEmpty())
                    <fieldset class="mt-6">
                        <legend class="text-sm font-medium text-gray-700">Apply to goods receipts</legend>
                        <div class="mt-2 flex gap-4 text-sm">
                            <label class="flex items-center gap-2"><input type="radio" name="allocation_mode" value="fifo" x-model="mode" class="text-brand-600"> Oldest first (automatic)</label>
                            <label class="flex items-center gap-2"><input type="radio" name="allocation_mode" value="manual" x-model="mode" class="text-brand-600"> Choose amounts</label>
                        </div>

                        <x-ui.table class="mt-3 shadow-none ring-1 ring-gray-200">
                            <x-slot:head>
                                <th>Goods receipt</th>
                                <th>Supplier invoice</th>
                                <th>Received</th>
                                <th class="text-right">Unpaid</th>
                                <th class="text-right" x-show="mode === 'manual'">Pay now</th>
                            </x-slot:head>
                            @foreach ($openReceipts as $receipt)
                                <tr>
                                    <td class="font-mono">{{ $receipt->number }}</td>
                                    <td>{{ $receipt->supplier_invoice_no ?: '—' }}</td>
                                    <td>{{ $receipt->received_at->format('Y-m-d') }}</td>
                                    <td class="text-right tabular">{{ $money($receipt->outstanding()) }}</td>
                                    <td class="text-right" x-show="mode === 'manual'">
                                        <input type="text" inputmode="decimal" name="allocations[{{ $receipt->id }}]" value="{{ old('allocations.'.$receipt->id) }}" class="w-32 rounded-md border-gray-300 text-right text-sm tabular" placeholder="0.00" aria-label="Pay now on {{ $receipt->number }}">
                                        @error('allocations.'.$receipt->id)<p class="text-xs text-red-700">{{ $message }}</p>@enderror
                                    </td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                        <p class="mt-2 text-xs text-gray-500">Money not applied to a goods receipt stays with the supplier as an advance.</p>
                    </fieldset>
                @else
                    <input type="hidden" name="allocation_mode" value="fifo">
                    <p class="mt-4 text-sm text-gray-500">No unpaid goods receipts: the whole amount is kept as an advance.</p>
                @endif

                <x-slot:footer>
                    <x-ui.button variant="secondary" :href="route('purchasing.suppliers.show', $supplier)">Cancel</x-ui.button>
                    <x-ui.button type="submit" x-bind:disabled="busy">Record payment</x-ui.button>
                </x-slot:footer>
            </x-ui.card>
        </form>
    @endif
@endsection
