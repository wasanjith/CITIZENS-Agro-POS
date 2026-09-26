@extends('layouts.app')

@section('title', 'Dashboard')

@php
    $money = fn ($value) => 'Rs. '.number_format((float) (string) $value, 2);
    $canAdmin = $user->can('admin.terminals.manage');
@endphp

@section('content')
    <x-ui.page-header :title="'Welcome, '.$user->name" :description="now()->format('l, j F Y')">
        @if (isset($data['sales']))
            <x-ui.button variant="secondary" :href="route('reports.show', 'daily-sales')">Today's Z report</x-ui.button>
        @endif
        @can('pos.live_view')
            <x-ui.button :href="route('admin.live-billing')">Live Billing</x-ui.button>
        @endcan
    </x-ui.page-header>

    @if ($user->isSuperAdmin() && ! $user->two_factor_confirmed_at)
        <x-ui.alert type="warning" title="Protect the owner account" class="mb-6">
            Two-factor authentication is not turned on. It is required before the dashboard is opened remotely from a phone.
            <a href="{{ route('account') }}" class="font-semibold underline">Turn it on</a>
        </x-ui.alert>
    @endif

    {{-- Today at a glance --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @isset($data['sales'])
            <x-ui.stat-tile label="Net sales today" :value="$money($data['sales']['net'])" :hint="$data['sales']['invoices'].' invoices settled'.((float) $data['sales']['returns'] > 0 ? ' · '.$money($data['sales']['returns']).' returned' : '')" :href="route('reports.show', 'daily-sales')" />
        @endisset
        @isset($data['waiting'])
            <x-ui.stat-tile
                label="Waiting for settlement"
                :value="$data['waiting']['count']"
                :hint="$data['waiting']['count'] > 0 ? $money($data['waiting']['total']).' · oldest '.$data['waiting']['oldest_minutes'].' min' : 'Nothing waiting'"
                :href="$user->can('pos.live_view') ? route('admin.live-billing') : null"
                @class(['ring-amber-300 bg-amber-50' => ($data['waiting']['oldest_minutes'] ?? 0) >= 10])
            />
        @endisset
        @isset($data['drawer'])
            <x-ui.stat-tile label="Cash in drawer (expected)" :value="$data['drawer']['cash'] !== null ? $money($data['drawer']['cash']) : 'Drawer closed'" :hint="$data['drawer']['holder'] ? 'Held by '.$data['drawer']['holder'] : null" />
        @endisset
        @isset($data['approvals'])
            <x-ui.stat-tile label="Approvals waiting" :value="$data['approvals']" :hint="$data['approvals'] > 0 ? 'Discounts / price changes from the counters' : 'None'" @class(['ring-amber-300 bg-amber-50' => $data['approvals'] > 0]) />
        @endisset
        @if (! isset($data['sales']))
            <x-ui.stat-tile label="This device" :value="$terminal?->displayName() ?? 'Not registered'" :hint="$terminal ? $terminal->type->label() : 'PIN sign-in is disabled here'" />
            <x-ui.stat-tile label="Cashier authority" :value="$cashierHolder?->name ?? '—'" :hint="$activeDelegation ? 'Delegated until '.$activeDelegation->expires_at->format('H:i') : 'Owner'" />
        @endif
    </div>

    {{-- Point of sale shortcuts --}}
    @if (($terminal && $user->can('pos.sell')) || ($terminal?->isMainCashier() && $user->canAny(['pos.settle', 'drawer.manage'])) || $user->canAny(['pos.live_view', 'drawer.handover']))
        <div class="mt-6 flex flex-wrap gap-2">
            @if ($terminal && $user->can('pos.sell'))
                <x-ui.button :href="route('pos.counter')">Billing screen</x-ui.button>
            @endif
            @if ($terminal?->isMainCashier() && $user->canAny(['pos.settle', 'drawer.manage']))
                <x-ui.button :href="route('pos.cashier')">Cashier</x-ui.button>
            @endif
            @can('pos.live_view')
                <x-ui.button variant="secondary" :href="route('sales.index')">Invoices</x-ui.button>
            @endcan
            @can('drawer.handover')
                <x-ui.button variant="secondary" :href="route('admin.delegations.index')">Cashier authority</x-ui.button>
            @endcan
        </div>
    @endif

    <div class="mt-6 grid items-start gap-6 lg:grid-cols-3 [&>*]:min-w-0">
        {{-- Sales by hour --}}
        @isset($data['hours'])
            @php
                $max = max(1.0, ...array_map(fn ($hour) => (float) $hour['net'], $data['hours']));
            @endphp
            <x-ui.card title="Sales by hour today" description="Net sales settled in each hour" class="lg:col-span-2">
                <div class="flex gap-3">
                    <div class="flex h-48 flex-col justify-between py-0.5 text-right text-xs tabular text-gray-500" aria-hidden="true">
                        <span>{{ number_format($max) }}</span>
                        <span>{{ number_format($max / 2) }}</span>
                        <span>0</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="relative flex h-48 items-end gap-0.5 border-b border-gray-300" x-data="{ tip: null }" @mouseleave="tip = null">
                            <div class="pointer-events-none absolute inset-x-0 top-0 border-t border-dashed border-gray-200"></div>
                            <div class="pointer-events-none absolute inset-x-0 top-1/2 border-t border-dashed border-gray-200"></div>
                            @foreach ($data['hours'] as $hour)
                                @php
                                    $label = sprintf('%02d:00–%02d:00', $hour['hour'], $hour['hour'] + 1);
                                    $tip = $label.' · '.$money($hour['net']).' · '.$hour['invoices'].' invoices';
                                    $height = max(0, (float) $hour['net']) / $max * 100;
                                @endphp
                                <div class="group relative flex h-full flex-1 items-end" @mouseenter="tip = @js($tip)" @focus="tip = @js($tip)" tabindex="0" aria-label="{{ $tip }}">
                                    <div class="w-full rounded-t bg-brand-600 group-hover:bg-brand-700" style="height: {{ $height }}%; min-height: {{ (float) $hour['net'] > 0 ? '2px' : '0' }}"></div>
                                </div>
                            @endforeach
                            <div x-show="tip" x-cloak x-text="tip" class="pointer-events-none absolute -top-2 left-1/2 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded bg-gray-900 px-2 py-1 text-xs text-white shadow"></div>
                        </div>
                        <div class="mt-1 flex gap-0.5 text-center text-[10px] tabular text-gray-500" aria-hidden="true">
                            @foreach ($data['hours'] as $hour)
                                <span class="flex-1">{{ $hour['hour'] % 2 === 0 ? sprintf('%02d', $hour['hour']) : '' }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>
                <table class="sr-only">
                    <caption>Net sales by hour today</caption>
                    @foreach ($data['hours'] as $hour)
                        <tr><th>{{ sprintf('%02d:00', $hour['hour']) }}</th><td>{{ $money($hour['net']) }}</td></tr>
                    @endforeach
                </table>
            </x-ui.card>
        @endisset

        {{-- Sales per counter --}}
        @isset($data['sales'])
            <x-ui.card title="Today per counter">
                @if ($data['sales']['counters'] === [])
                    <p class="text-sm text-gray-500">No sales settled yet today.</p>
                @else
                    <ul class="divide-y divide-gray-100 text-sm">
                        @foreach ($data['sales']['counters'] as $counter)
                            <li class="flex items-center justify-between py-2">
                                <span class="font-medium text-gray-900">{{ $counter['name'] }}</span>
                                <span class="text-right tabular">{{ $money($counter['net']) }}<span class="block text-xs text-gray-500">{{ $counter['invoices'] }} invoices</span></span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <x-slot:footer>
                    <x-ui.button variant="link" :href="route('reports.show', 'sales-by-counter')">Sales by counter</x-ui.button>
                </x-slot:footer>
            </x-ui.card>
        @endisset

        {{-- Cashier authority --}}
        @isset($data['drawer'])
            @can('drawer.handover')
                <x-ui.card title="Cashier authority">
                    <p class="text-sm text-gray-500">Held by</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $data['drawer']['holder'] ?? 'Nobody (drawer closed)' }}</p>
                    @if ($data['drawer']['delegation'])
                        <p class="mt-1 text-sm text-gray-600">Handed over by {{ $data['drawer']['delegation']->fromUser?->name }} until {{ $data['drawer']['delegation']->expires_at->format('H:i') }}.</p>
                        <form method="POST" action="{{ route('admin.delegations.revoke', $data['drawer']['delegation']) }}" class="mt-3" x-data @submit="if (! confirm('Take back the cashier authority now? They must count and close the drawer.')) $event.preventDefault()">
                            @csrf
                            <x-ui.button type="submit" variant="danger" size="sm">Revoke now</x-ui.button>
                        </form>
                    @endif
                </x-ui.card>
            @endcan
        @endisset

        {{-- Stock alerts --}}
        @isset($data['stock'])
            <x-ui.card title="Stock alerts">
                <div class="grid grid-cols-2 gap-3">
                    <a href="{{ route('reports.show', 'reorder-list') }}" class="rounded-md p-3 ring-1 ring-gray-200 hover:bg-gray-50">
                        <span class="block text-2xl font-semibold tabular {{ $data['stock']['low'] > 0 ? 'text-amber-700' : 'text-gray-900' }}">{{ $data['stock']['low'] }}</span>
                        <span class="text-xs text-gray-500">at or below reorder level</span>
                    </a>
                    <a href="{{ route('reports.show', ['report' => 'expiry', 'days' => $data['stock']['expiry_days']]) }}" class="rounded-md p-3 ring-1 ring-gray-200 hover:bg-gray-50">
                        <span class="block text-2xl font-semibold tabular {{ $data['stock']['expiring'] > 0 ? 'text-amber-700' : 'text-gray-900' }}">{{ $data['stock']['expiring'] }}</span>
                        <span class="text-xs text-gray-500">batches expiring within {{ $data['stock']['expiry_days'] }} days</span>
                    </a>
                </div>
                @if ($data['stock']['low_items'])
                    <ul class="mt-3 divide-y divide-gray-100 text-sm">
                        @foreach ($data['stock']['low_items'] as $item)
                            <li class="flex justify-between gap-3 py-1.5">
                                <a href="{{ route('catalog.products.show', $item['id']) }}" class="truncate hover:underline"><span class="font-mono text-xs text-gray-500">{{ $item['code'] }}</span> {{ $item['name'] }}</a>
                                <span class="shrink-0 tabular text-gray-600">{{ rtrim(rtrim($item['on_hand'], '0'), '.') ?: '0' }} / {{ rtrim(rtrim($item['reorder_level'], '0'), '.') }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        @endisset

        {{-- Purchasing --}}
        @isset($data['purchasing'])
            <x-ui.card title="Purchasing">
                <ul class="divide-y divide-gray-100 text-sm">
                    <li class="flex justify-between py-2"><a href="{{ route('purchasing.purchase-orders.index', ['filter' => ['status' => 'submitted']]) }}" class="hover:underline">Orders waiting for approval</a><span @class(['font-semibold tabular', 'text-amber-700' => $data['purchasing']['to_approve'] > 0])>{{ $data['purchasing']['to_approve'] }}</span></li>
                    <li class="flex justify-between py-2"><a href="{{ route('reports.show', 'open-purchase-orders') }}" class="hover:underline">Orders not yet received</a><span class="font-semibold tabular">{{ $data['purchasing']['to_receive'] }}</span></li>
                    <li class="flex justify-between py-2"><a href="{{ route('purchasing.goods-receipts.index') }}" class="hover:underline">Goods receipts not posted</a><span @class(['font-semibold tabular', 'text-amber-700' => $data['purchasing']['draft_receipts'] > 0])>{{ $data['purchasing']['draft_receipts'] }}</span></li>
                </ul>
            </x-ui.card>
        @endisset

        {{-- Cheques --}}
        @isset($data['cheques'])
            <x-ui.card title="Cheques due">
                <ul class="divide-y divide-gray-100 text-sm">
                    <li class="flex justify-between gap-3 py-2"><span>Received, ready to deposit</span><span class="text-right tabular"><b>{{ $data['cheques']['to_deposit'] }}</b><span class="block text-xs text-gray-500">{{ $money($data['cheques']['to_deposit_total']) }}</span></span></li>
                    <li class="flex justify-between gap-3 py-2"><span>Issued, due within {{ $data['cheques']['days'] }} days</span><span class="text-right tabular"><b>{{ $data['cheques']['issued_due'] }}</b><span class="block text-xs text-gray-500">{{ $money($data['cheques']['issued_due_total']) }}</span></span></li>
                </ul>
                <x-slot:footer>
                    <x-ui.button variant="link" :href="route('finance.cheques.calendar')">Cheque calendar</x-ui.button>
                </x-slot:footer>
            </x-ui.card>
        @endisset

        {{-- Receivables / payables --}}
        @isset($data['balances'])
            <x-ui.card title="Owed">
                <ul class="divide-y divide-gray-100 text-sm">
                    <li class="flex justify-between py-2"><a href="{{ route('reports.show', 'receivables-ageing') }}" class="hover:underline">Customers owe the shop</a><span class="font-semibold tabular">{{ $money($data['balances']['receivable']) }}</span></li>
                    <li class="flex justify-between py-2"><a href="{{ route('reports.show', 'supplier-ageing') }}" class="hover:underline">The shop owes suppliers</a><span class="font-semibold tabular">{{ $money($data['balances']['payable']) }}</span></li>
                </ul>
            </x-ui.card>
        @endisset

        {{-- Top items --}}
        @isset($data['topItems'])
            <x-ui.card title="Top 10 items" description="Last 30 days, net sales">
                @if ($data['topItems'] === [])
                    <p class="text-sm text-gray-500">No sales in the last 30 days.</p>
                @else
                    <ol class="divide-y divide-gray-100 text-sm">
                        @foreach ($data['topItems'] as $item)
                            <li class="flex justify-between gap-3 py-1.5">
                                <span class="truncate"><span class="font-mono text-xs text-gray-500">{{ $item['code'] }}</span> {{ $item['name'] }}</span>
                                <span class="shrink-0 tabular">{{ number_format((float) $item['net'], 2) }}</span>
                            </li>
                        @endforeach
                    </ol>
                @endif
                <x-slot:footer>
                    <x-ui.button variant="link" :href="route('reports.show', ['report' => 'sales-by-item', 'from' => today()->subDays(29)->toDateString(), 'to' => today()->toDateString()])">Sales by item</x-ui.button>
                </x-slot:footer>
            </x-ui.card>
        @endisset

        {{-- System (owner / admin) --}}
        @if ($canAdmin)
            <x-ui.card title="System">
                <ul class="divide-y divide-gray-100 text-sm">
                    <li class="flex justify-between py-2"><span>This device</span><span class="font-medium">{{ $terminal?->displayName() ?? 'Not registered' }}</span></li>
                    <li class="flex justify-between py-2"><a href="{{ route('admin.terminals.index') }}" class="hover:underline">Terminals registered</a><span class="tabular">{{ $stats['registeredTerminals'] }} / {{ $stats['terminals'] }}</span></li>
                    <li class="flex justify-between py-2"><a href="{{ route('admin.printers.index') }}" class="hover:underline">Printers</a><span class="tabular">{{ $stats['printers'] }}</span></li>
                    <li class="flex justify-between py-2"><a href="{{ route('admin.users.index') }}" class="hover:underline">Active users</a><span class="tabular">{{ $stats['users'] }}</span></li>
                </ul>
            </x-ui.card>
        @endif
    </div>
@endsection
