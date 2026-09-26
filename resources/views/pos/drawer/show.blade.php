@extends('layouts.pos')

@section('title', 'Cash drawer')

@php
    $money = fn ($value) => 'Rs. '.number_format((float) $value, 2);
@endphp

@section('content')
    <div class="mx-auto max-w-5xl space-y-6 p-4">
        @if ($session === null)
            <x-ui.page-header title="Cash drawer" :description="$terminal->displayName().' · no drawer is open.'">
                @can('drawer.manage')
                    <x-ui.button :href="route('pos.drawer.open')">Open drawer</x-ui.button>
                @endcan
            </x-ui.page-header>
            @cannot('drawer.manage')
                <x-ui.alert type="info">Only the cashier-authority holder can open the drawer.</x-ui.alert>
            @endcannot
        @else
            <x-ui.page-header title="Cash drawer" :description="'Held by '.$session->holder->name.' since '.$session->opened_at->format('Y-m-d H:i')">
                <x-ui.button variant="secondary" :href="route('pos.drawer.report', $session)" target="_blank">X report</x-ui.button>
                @if ($isHolder)
                    @can('pos.settle')
                        <x-ui.button variant="secondary" :href="route('pos.cashier')">Cashier screen</x-ui.button>
                    @endcan
                    @can('drawer.handover')
                        <x-ui.button variant="secondary" :href="route('pos.handover.create')">Hand over</x-ui.button>
                    @else
                        <x-ui.button variant="secondary" :href="route('pos.handover.return')">Count and hand back</x-ui.button>
                    @endcan
                    @can('drawer.manage')
                        <x-ui.button :href="route('pos.drawer.close')">Close day</x-ui.button>
                    @endcan
                @elseif (auth()->user()->can('drawer.handover'))
                    <x-ui.button :href="route('pos.handover.return')">Take the drawer back</x-ui.button>
                @endif
            </x-ui.page-header>

            <div class="grid gap-4 sm:grid-cols-4">
                <x-ui.stat-tile label="Opening float" :value="$money($summary['opening_float'])" />
                <x-ui.stat-tile label="Cash settled" :value="$money($summary['cash_sales'])" :hint="((float) $summary['cash_refunds'] > 0 ? 'Refunds -'.$money($summary['cash_refunds']).' · ' : '').((float) $summary['customer_cash'] > 0 ? 'Customer payments +'.$money($summary['customer_cash']) : '')" />
                <x-ui.stat-tile label="Pay in / out / drops" :value="'+'.number_format((float) $summary['movements']['pay_in']['amount'], 2).' / -'.number_format((float) $summary['movements']['pay_out']['amount'] + (float) $summary['movements']['safe_drop']['amount'], 2)" />
                <x-ui.stat-tile label="Expected in drawer" :value="$money($summary['expected_cash'])" />
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <x-ui.card title="Payments by method" class="lg:col-span-1">
                    @forelse ($summary['by_method'] as $row)
                        <div class="flex justify-between py-1 text-sm"><span>{{ $row['label'] }} ({{ $row['count'] }})</span><span class="tabular">{{ $money($row['amount']) }}</span></div>
                    @empty
                        <p class="text-sm text-gray-500">No settlements yet.</p>
                    @endforelse
                </x-ui.card>

                <x-ui.card title="Pay in · pay out · safe drop" class="lg:col-span-2">
                    @if ($isHolder && auth()->user()->can('drawer.manage'))
                        <form method="POST" action="{{ route('pos.drawer.movements.store') }}" class="grid gap-3 sm:grid-cols-4">
                            @csrf
                            <x-ui.select name="type" label="Type" :options="$movementTypes" required />
                            <x-ui.money-input name="amount" label="Amount" required />
                            <x-ui.input name="reason" label="Reason" required maxlength="255" class="sm:col-span-2" />
                            <p class="text-xs text-gray-500 sm:col-span-4">Pay in = change brought from home. Pay out = cash the owner takes for personal use. Safe drop = cash sent home during the day. At closing the counted cash goes home. Shop expenses go through <a href="{{ route('finance.expenses.create') }}" class="text-brand-700 hover:underline">Expenses</a> (petty cash).</p>
                            <div class="sm:col-span-4 flex justify-end"><x-ui.button type="submit">Record</x-ui.button></div>
                        </form>
                    @endif

                    <ul class="mt-4 divide-y divide-gray-100 text-sm">
                        @forelse ($session->cashMovements->sortByDesc('id') as $movement)
                            <li class="flex items-center justify-between gap-3 py-2">
                                <span><span class="font-medium">{{ $movement->type->label() }}</span> · {{ $movement->reason }} <span class="text-gray-500">· {{ $movement->user->name }} {{ $movement->created_at->format('H:i') }}</span></span>
                                <span class="tabular {{ $movement->type->sign() < 0 ? 'text-red-700' : 'text-brand-700' }}">{{ $movement->type->sign() < 0 ? '-' : '+' }}{{ number_format((float) $movement->amount, 2) }}</span>
                            </li>
                        @empty
                            <li class="py-2 text-gray-500">No pay ins or pay outs.</li>
                        @endforelse
                    </ul>
                </x-ui.card>
            </div>
        @endif

        @if ($recent->isNotEmpty())
            <x-ui.card title="Recently closed">
                <ul class="divide-y divide-gray-100 text-sm">
                    @foreach ($recent as $closed)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span>{{ $closed->holder->name }} · {{ $closed->opened_at->format('Y-m-d H:i') }} – {{ $closed->closed_at->format('H:i') }} · {{ $closed->close_reason?->label() }}</span>
                            <span class="flex items-center gap-3">
                                <span class="tabular {{ (float) $closed->variance < 0 ? 'text-red-700' : '' }}">Variance {{ number_format((float) $closed->variance, 2) }}</span>
                                <a class="text-brand-700 hover:underline" href="{{ route('pos.drawer.report', ['drawerSession' => $closed, 'print' => 1]) }}" target="_blank">Print</a>
                                <a class="text-brand-700 hover:underline" href="{{ route('pos.drawer.report-pdf', $closed) }}">PDF</a>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif
    </div>
@endsection
