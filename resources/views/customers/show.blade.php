@extends('layouts.app')

@php
    use App\Domain\Customers\Services\CustomerStatement;
    $money = fn ($value) => number_format((float) (string) $value, 2);
    $atCashier = auth()->user()->can('receivePayment', $customer) && app(\App\Domain\Identity\Support\CurrentTerminal::class)->get()?->isMainCashier();
@endphp

@section('title', $customer->name)

@section('content')
    <x-ui.page-header :title="$customer->name" :description="$customer->code.($customer->name_si ? ' · '.$customer->name_si : '').($customer->is_active ? '' : ' · Inactive')">
        <x-ui.button variant="secondary" :href="route('customers.statement', $customer)">Statement PDF</x-ui.button>
        @if ($atCashier)
            <x-ui.button :href="route('pos.customer-payments.create', ['customer' => $customer->id])">Receive payment</x-ui.button>
        @endif
        @can('update', $customer)
            <x-ui.button variant="secondary" :href="route('customers.edit', $customer)">Edit</x-ui.button>
        @endcan
        @can('delete', $customer)
            <x-ui.button variant="secondary" class="text-red-700" x-data x-on:click="$dispatch('open-modal', 'delete-customer')">Delete</x-ui.button>
            <x-ui.confirm-modal name="delete-customer" :action="route('customers.destroy', $customer)" method="DELETE" title="Delete {{ $customer->name }}?" confirm="Delete">
                Only customers without invoices or account entries can be deleted. Otherwise mark the customer inactive.
            </x-ui.confirm-modal>
        @endcan
    </x-ui.page-header>

    <div class="mb-6 grid gap-4 sm:grid-cols-4">
        <x-ui.stat-tile label="Balance owed" :value="'Rs. '.$money($balance)" :hint="$balance->isNegative() ? 'Advance held for the customer' : null" />
        <x-ui.stat-tile label="Credit limit" :value="'Rs. '.$money($customer->credit_limit)" :hint="'Available Rs. '.$money($customer->availableCredit($balance)).' · '.$customer->credit_days.' days'" />
        <x-ui.stat-tile label="Overdue" :value="'Rs. '.$money($ageing['1_30'] + $ageing['31_60'] + $ageing['61_90'] + $ageing['over_90'])" :hint="$openInvoices->count().' unpaid '.str('invoice')->plural($openInvoices->count())" />
        <x-ui.stat-tile label="Contact" :value="$customer->phone ?: '—'" :hint="trim(($customer->area ?? '').' '.($customer->nic ? '· NIC '.$customer->nic : ''))" />
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Unpaid credit invoices" class="lg:col-span-2">
            @if ($openInvoices->isEmpty())
                <p class="text-sm text-gray-500">Nothing owed on invoices.</p>
            @else
                <x-ui.table class="shadow-none ring-0">
                    <x-slot:head>
                        <th>Invoice</th>
                        <th>Date</th>
                        <th>Due</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Owed</th>
                    </x-slot:head>
                    @foreach ($openInvoices as $sale)
                        <tr>
                            <td><a href="{{ route('sales.show', $sale) }}" class="font-mono text-brand-700 hover:underline">{{ $sale->invoice_no }}</a></td>
                            <td class="text-gray-600">{{ $sale->settled_at?->format('Y-m-d') }}</td>
                            <td @class(['text-red-700 font-medium' => $sale->isOverdue(), 'text-gray-600' => ! $sale->isOverdue()])>{{ $sale->due_date?->format('Y-m-d') }} @if ($sale->isOverdue())<span class="text-xs">({{ (int) $sale->due_date->diffInDays(today()) }} days late)</span>@endif</td>
                            <td class="text-right tabular">{{ $money($sale->total) }}</td>
                            <td class="text-right tabular font-medium">{{ $money($sale->balance_due) }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>

        <x-ui.card title="Ageing">
            <dl class="space-y-1 text-sm">
                @foreach (CustomerStatement::bucketLabels() as $key => $label)
                    <div class="flex justify-between"><dt class="text-gray-600">{{ $label }}</dt><dd @class(['tabular', 'font-semibold text-red-700' => $key !== 'current' && (float) $ageing[$key] > 0])>{{ $money($ageing[$key]) }}</dd></div>
                @endforeach
            </dl>
            <p class="mt-4 text-xs text-gray-500">Price list: {{ $customer->priceList?->name ?? 'Default' }}</p>
            @if ($customer->address)<p class="mt-1 text-xs text-gray-500">{{ $customer->address }}</p>@endif
            @if ($customer->notes)<p class="mt-1 text-xs text-gray-500">{{ $customer->notes }}</p>@endif
        </x-ui.card>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-ui.card title="Recent invoices">
            @if ($recentSales->isEmpty())
                <p class="text-sm text-gray-500">No invoices yet.</p>
            @else
                <ul class="divide-y divide-gray-100 text-sm">
                    @foreach ($recentSales as $sale)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <a href="{{ route('sales.show', $sale) }}" class="font-mono text-brand-700 hover:underline">{{ $sale->invoice_no }}</a>
                            <span class="text-gray-500">{{ $sale->invoiced_at?->format('Y-m-d') }} · {{ $sale->payment_method_intent->label() }}</span>
                            <x-ui.badge :color="$sale->status->color()">{{ $sale->status->label() }}</x-ui.badge>
                            <span class="tabular">{{ $money($sale->total) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        <x-ui.card title="Recent payments">
            @if ($payments->isEmpty())
                <p class="text-sm text-gray-500">No payments yet.</p>
            @else
                <ul class="divide-y divide-gray-100 text-sm">
                    @foreach ($payments as $payment)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <a href="{{ route('customers.payments.show', $payment) }}" class="font-mono text-brand-700 hover:underline">{{ $payment->number }}</a>
                            <span class="text-gray-500">{{ $payment->date->format('Y-m-d') }} · {{ $payment->method->label() }} · {{ $payment->receivedBy->name }}</span>
                            <span class="tabular">{{ $money($payment->amount) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>

    <x-ui.card title="Account" description="Debit = the customer owes more (credit sales). Credit = owes less (payments, returns)." class="mt-6">
        @if ($ledger->isEmpty())
            <p class="text-sm text-gray-500">No entries yet.</p>
        @else
            <x-ui.table class="shadow-none ring-0">
                <x-slot:head>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Reference</th>
                    <th>Due</th>
                    <th>Note</th>
                    <th class="text-right">Debit</th>
                    <th class="text-right">Credit</th>
                </x-slot:head>
                @foreach ($ledger as $entry)
                    <tr>
                        <td class="whitespace-nowrap text-gray-600">{{ $entry->date->format('Y-m-d') }}</td>
                        <td>{{ $entry->type->label() }}</td>
                        <td class="font-mono">{{ $entry->reference }}</td>
                        <td class="text-gray-600">{{ $entry->due_date?->format('Y-m-d') }}</td>
                        <td class="text-gray-600">{{ $entry->note ?: '—' }}</td>
                        <td class="text-right tabular">{{ (float) $entry->debit ? $money($entry->debit) : '' }}</td>
                        <td class="text-right tabular">{{ (float) $entry->credit ? $money($entry->credit) : '' }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
            <x-ui.pagination :paginator="$ledger" />
        @endif
    </x-ui.card>
@endsection
