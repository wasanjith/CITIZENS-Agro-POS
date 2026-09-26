@extends('layouts.app')

@php $money = fn ($value) => number_format((float) (string) $value, 2); @endphp

@section('title', 'Receive customer payment')

@section('content')
    <x-ui.page-header title="Receive customer payment" description="Cash goes into your drawer. The receipt prints on the main printer.">
        <x-ui.button variant="secondary" :href="route('pos.cashier')">Back to cashier</x-ui.button>
    </x-ui.page-header>

    @foreach (['drawer', 'allocations', 'amount', 'method', 'reference'] as $field)
        @error($field)<x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>@enderror
    @endforeach

    <form method="GET" action="{{ route('pos.customer-payments.create') }}" class="mb-6 max-w-xl" x-data x-on:search-select-changed="$nextTick(() => $el.submit())">
        <x-ui.search-select name="customer" label="Customer" :url="route('api.customers')" :value="$customer?->id" :value-label="$customer ? $customer->name.' ('.$customer->code.')' : ''" placeholder="Phone, name, NIC or village" />
    </form>

    @if ($customer)
        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <x-ui.stat-tile label="Balance owed" :value="'Rs. '.$money($balance)" />
            <x-ui.stat-tile label="Unpaid invoices" :value="$openInvoices->count()" :hint="'Rs. '.$money($openInvoices->sum('balance_due'))" />
            <x-ui.stat-tile label="Customer" :value="$customer->code" :hint="$customer->phone" />
        </div>

        <form method="POST" action="{{ route('pos.customer-payments.store', $customer) }}"
              x-data="{ mode: @js(old('allocation_mode', 'fifo')), method: @js(old('method', 'cash')), amount: @js(old('amount', '')), busy: false }"
              @submit="busy = true">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

            <x-ui.card>
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.money-input name="amount" label="Amount received (Rs.)" required x-model="amount" autofocus />
                    <x-ui.select name="method" label="Paid by" :options="$methods" required x-model="method" />
                    <div x-show="method !== 'cash'" x-cloak>
                        <x-ui.input name="reference" label="Reference" hint="Slip, transfer or cheque number." />
                    </div>
                    <div x-show="method === 'cheque'" x-cloak class="grid gap-4 sm:col-span-3 sm:grid-cols-3">
                        <x-ui.input name="cheque_bank" label="Cheque bank" maxlength="100" />
                        <x-ui.input name="cheque_branch" label="Branch" maxlength="100" />
                        <x-ui.date-input name="cheque_date" label="Cheque date" :value="old('cheque_date', today()->toDateString())" hint="A later date makes it a post-dated cheque." />
                    </div>
                    <x-ui.input name="note" label="Note" class="sm:col-span-3" maxlength="255" />
                </div>

                @if ($openInvoices->isNotEmpty())
                    <fieldset class="mt-6">
                        <legend class="text-sm font-medium text-gray-700">Apply to invoices</legend>
                        <div class="mt-2 flex gap-4 text-sm">
                            <label class="flex items-center gap-2"><input type="radio" name="allocation_mode" value="fifo" x-model="mode" class="text-brand-600"> Oldest first (automatic)</label>
                            <label class="flex items-center gap-2"><input type="radio" name="allocation_mode" value="manual" x-model="mode" class="text-brand-600"> Choose amounts</label>
                        </div>

                        <x-ui.table class="mt-3 shadow-none ring-1 ring-gray-200">
                            <x-slot:head>
                                <th>Invoice</th>
                                <th>Due</th>
                                <th class="text-right">Owed</th>
                                <th class="text-right" x-show="mode === 'manual'">Pay now</th>
                            </x-slot:head>
                            @foreach ($openInvoices as $sale)
                                <tr>
                                    <td class="font-mono">{{ $sale->invoice_no }}</td>
                                    <td @class(['text-red-700' => $sale->isOverdue()])>{{ $sale->due_date?->format('Y-m-d') }}</td>
                                    <td class="text-right tabular">{{ $money($sale->balance_due) }}</td>
                                    <td class="text-right" x-show="mode === 'manual'">
                                        <input type="text" inputmode="decimal" name="allocations[{{ $sale->id }}]" value="{{ old('allocations.'.$sale->id) }}" class="w-32 rounded-md border-gray-300 text-right text-sm tabular" placeholder="0.00" aria-label="Pay now on {{ $sale->invoice_no }}">
                                        @error('allocations.'.$sale->id)<p class="text-xs text-red-700">{{ $message }}</p>@enderror
                                    </td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                        <p class="mt-2 text-xs text-gray-500">Money not applied to an invoice stays on the account as an advance.</p>
                    </fieldset>
                @else
                    <input type="hidden" name="allocation_mode" value="fifo">
                    <p class="mt-4 text-sm text-gray-500">No unpaid invoices: the whole amount is kept as an advance.</p>
                @endif

                <x-slot:footer>
                    <x-ui.button variant="secondary" :href="route('customers.show', $customer)">Cancel</x-ui.button>
                    <x-ui.button type="submit" x-bind:disabled="busy">Receive and print receipt</x-ui.button>
                </x-slot:footer>
            </x-ui.card>
        </form>
    @endif
@endsection
