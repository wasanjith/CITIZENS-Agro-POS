@extends('layouts.app')

@section('title', 'Dashboard')

{{-- Full width and tight spacing so the whole dashboard fits one 1080p screen. --}}
@section('main_class', 'px-3 py-2 sm:px-4 lg:px-5')

@use('App\Domain\Finance\Models\Expense')
@use('App\Domain\Inventory\Models\PackOpening')
@use('App\Domain\Inventory\Models\Stocktake')
@use('App\Domain\Purchasing\Models\GoodsReceipt')
@use('App\Domain\Purchasing\Models\PurchaseOrder')
@use('App\Domain\Purchasing\Models\SupplierPayment')

@php
    $money = fn ($value) => 'Rs. '.number_format((float) (string) $value, 2);
    $qty = fn ($value) => rtrim(rtrim((string) $value, '0'), '.') ?: '0';
    $today = today()->toDateString();
    $monthStart = today()->startOfMonth()->toDateString();
    $methodColors = ['cash' => 'green', 'card' => 'blue', 'bank_transfer' => 'purple', 'cheque' => 'amber', 'credit' => 'red', 'split' => 'gray'];
    $methodShort = ['bank_transfer' => 'Bank'];
    $methodText = ['cash' => 'text-brand-700', 'card' => 'text-sky-700', 'bank_transfer' => 'text-violet-700', 'cheque' => 'text-amber-700', 'credit' => 'text-red-700'];
@endphp

@section('content')
    {{-- Needs attention: only what is waiting on someone right now --}}
    @php
        $attention = array_values(array_filter([
            $user->isSuperAdmin() && ! $user->two_factor_confirmed_at ? ['text' => 'Two-factor authentication is not turned on', 'title' => 'Required before the dashboard is opened remotely from a phone.', 'href' => route('account'), 'urgent' => false] : null,
            ($data['waiting']['count'] ?? 0) > 0 ? ['text' => $data['waiting']['count'].' waiting for settlement · oldest '.$data['waiting']['oldest_minutes'].' min', 'title' => $money($data['waiting']['total']), 'href' => $user->can('pos.live_view') ? route('admin.live-billing') : null, 'urgent' => $data['waiting']['oldest_minutes'] >= 10] : null,
            ($data['approvals'] ?? 0) > 0 ? ['text' => $data['approvals'].' approval(s) waiting', 'href' => $user->can('pos.live_view') ? route('admin.live-billing') : null, 'urgent' => true] : null,
            ($data['purchasing']['to_approve'] ?? 0) > 0 && $user->can('purchasing.po.approve') ? ['text' => $data['purchasing']['to_approve'].' PO(s) to approve', 'href' => route('purchasing.purchase-orders.index', ['filter' => ['status' => 'submitted']]), 'urgent' => false] : null,
            ($data['purchasing']['draft_receipts'] ?? 0) > 0 ? ['text' => $data['purchasing']['draft_receipts'].' GRN(s) not posted', 'href' => route('purchasing.goods-receipts.index'), 'urgent' => false] : null,
            ($data['supplierDues']['overdue'] ?? 0) > 0 ? ['text' => $data['supplierDues']['overdue'].' supplier bill(s) overdue', 'href' => route('reports.show', 'supplier-ageing'), 'urgent' => true] : null,
            ($data['cheques']['to_deposit'] ?? 0) > 0 ? ['text' => $data['cheques']['to_deposit'].' cheque(s) to deposit', 'title' => $money($data['cheques']['to_deposit_total']), 'href' => route('finance.cheques.calendar'), 'urgent' => false] : null,
            ($data['cheques']['issued_due'] ?? 0) > 0 ? ['text' => $data['cheques']['issued_due'].' issued cheque(s) due', 'title' => 'Within '.$data['cheques']['days'].' days · '.$money($data['cheques']['issued_due_total']), 'href' => route('finance.cheques.calendar'), 'urgent' => false] : null,
            ($data['stock']['expiring'] ?? 0) > 0 ? ['text' => $data['stock']['expiring'].' batch(es) expiring', 'title' => 'Within '.$data['stock']['expiry_days'].' days', 'href' => route('reports.show', ['report' => 'expiry', 'days' => $data['stock']['expiry_days']]), 'urgent' => false] : null,
        ]));
    @endphp

    {{-- One line: title, what needs attention, date and time --}}
    <div class="mb-2 flex flex-wrap items-center gap-x-4 gap-y-2">
        <h1 class="text-lg font-semibold text-gray-900">
            Dashboard <span class="ml-1 hidden text-sm font-normal text-gray-500 2xl:inline">Overview of today's business</span>
        </h1>

        @if ($attention || ($data['drawer']['delegation'] ?? null))
            <div class="order-last flex w-full min-w-0 flex-wrap items-center gap-2 lg:order-none lg:w-auto lg:flex-1" aria-label="Needs attention">
                @foreach ($attention as $item)
                    <a @if ($item['href']) href="{{ $item['href'] }}" @endif @isset($item['title']) title="{{ $item['title'] }}" @endisset @class([
                        'inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium ring-1',
                        'bg-red-50 text-red-800 ring-red-200' => $item['urgent'],
                        'bg-amber-50 text-amber-900 ring-amber-200' => ! $item['urgent'],
                        'hover:underline' => $item['href'],
                    ])>
                        <x-dashboard.icon name="warning" class="size-3.5" /> {{ $item['text'] }}
                    </a>
                @endforeach
                @if (($data['drawer']['delegation'] ?? null) && $user->can('drawer.handover'))
                    <form method="POST" action="{{ route('admin.delegations.revoke', $data['drawer']['delegation']) }}" class="inline-flex items-center gap-2 rounded-md bg-violet-50 px-2.5 py-1 text-xs font-medium text-violet-800 ring-1 ring-violet-200" x-data @submit="if (! confirm('Take back the cashier authority now? They must count and close the drawer.')) $event.preventDefault()">
                        @csrf
                        Cashier authority: {{ $data['drawer']['holder'] }} until {{ $data['drawer']['delegation']->expires_at->format('H:i') }}
                        <button type="submit" class="font-semibold text-red-700 hover:underline">Revoke</button>
                    </form>
                @endif
            </div>
        @endif

        <div class="ml-auto flex items-center gap-2 text-xs text-gray-700">
            <span class="inline-flex items-center gap-1.5 rounded-md bg-white px-2.5 py-1 ring-1 ring-gray-200">
                <x-dashboard.icon name="calendar" class="size-3.5 text-gray-500" /> {{ now()->format('j M Y') }}
            </span>
            <span class="inline-flex items-center gap-1.5 rounded-md bg-white px-2.5 py-1 tabular ring-1 ring-gray-200"
                x-data="{ time: @js(now()->format('h:i A')) }"
                x-init="setInterval(() => time = new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }), 15000)">
                <x-dashboard.icon name="clock" class="size-3.5 text-gray-500" /> <span x-text="time">{{ now()->format('h:i A') }}</span>
            </span>
        </div>
    </div>

    {{-- Staff without shop figures: this device and who holds the cash --}}
    @if (! isset($data['sales']))
        <div class="mb-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.stat-tile label="This device" :value="$terminal?->displayName() ?? 'Not registered'" :hint="$terminal ? $terminal->type->label() : 'PIN sign-in is disabled here'" />
            <x-ui.stat-tile label="Cashier authority" :value="$cashierHolder?->name ?? '—'" :hint="$activeDelegation ? 'Delegated until '.$activeDelegation->expires_at->format('H:i') : 'Owner'" />
        </div>
    @endif

    {{-- Row 1: key figures --}}
    @if (isset($data['sales']) || isset($data['profit']) || isset($data['stock']))
        <div class="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
            @isset($data['sales'])
                @php
                    $running = 0.0;
                    $line = [];
                    foreach ($data['hours'] ?? [] as $hour) {
                        if ($hour['hour'] > now()->hour) {
                            break;
                        }
                        $running += (float) $hour['net'];
                        $line[] = $running;
                    }
                    $change = $data['sales']['change'];
                @endphp
                <x-dashboard.kpi-card label="Today's total sales" :value="$money($data['sales']['net'])" icon="banknotes" accent="green" :href="route('reports.show', 'daily-sales')">
                    <x-slot:visual>
                        <x-dashboard.sparkline :values="$line" class="hidden 2xl:block" />
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600 2xl:hidden"><x-dashboard.icon name="banknotes" class="size-5" /></span>
                    </x-slot:visual>
                    <span class="inline-flex flex-wrap items-center gap-1.5">
                        vs yesterday
                        @if ($change === null)
                            <span class="text-gray-400">(none)</span>
                        @else
                            <span @class([
                                'inline-flex items-center gap-0.5 rounded px-1 py-px font-semibold tabular',
                                'bg-brand-50 text-brand-700' => (float) $change >= 0,
                                'bg-red-50 text-red-700' => (float) $change < 0,
                            ])>
                                <x-dashboard.icon :name="(float) $change >= 0 ? 'arrow-up' : 'arrow-down'" class="size-3" />
                                {{ number_format(abs((float) $change), 1) }}%
                            </span>
                        @endif
                        <span class="hidden text-gray-400 2xl:inline">· {{ $data['sales']['invoices'] }} invoices</span>
                    </span>
                </x-dashboard.kpi-card>
            @endisset

            @isset($data['profit'])
                <x-dashboard.kpi-card label="Gross profit today" :value="$money($data['profit']['today']['profit'])" icon="trending-up" accent="amber" :href="route('reports.show', ['report' => 'profit-by-item', 'from' => $today, 'to' => $today])">
                    Margin: <span class="font-semibold tabular">{{ number_format((float) $data['profit']['today']['margin'], 1) }}%</span>
                </x-dashboard.kpi-card>
            @endisset

            @isset($data['month'])
                <x-dashboard.kpi-card label="Sales this month" :value="$money($data['month']['net'])" icon="calendar" accent="blue" :href="route('reports.show', ['report' => 'daily-sales', 'from' => $monthStart, 'to' => $today])">
                    {{ number_format($data['month']['invoices']) }} invoices since {{ today()->startOfMonth()->format('j M') }}
                </x-dashboard.kpi-card>
            @endisset

            @isset($data['profit'])
                <x-dashboard.kpi-card label="Gross profit this month" :value="$money($data['profit']['month']['profit'])" icon="chart" accent="violet" :href="route('reports.show', ['report' => 'profit-by-day', 'from' => $monthStart, 'to' => $today])">
                    Margin: <span class="font-semibold tabular">{{ number_format((float) $data['profit']['month']['margin'], 1) }}%</span>
                </x-dashboard.kpi-card>
            @endisset

            @isset($data['stock'])
                <x-dashboard.kpi-card label="Total items" :value="number_format($data['stock']['active_items'])" icon="cube" accent="teal" :href="$user->can('catalog.view') ? route('catalog.products.index') : null">
                    Active items
                </x-dashboard.kpi-card>
            @endisset
        </div>
    @endif

    {{-- Row 2: today's summaries --}}
    <div class="mt-2.5 grid items-stretch gap-2.5 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
        @isset($data['payments'])
            <x-dashboard.panel title="Payment summary">
                <dl class="divide-y divide-gray-100 text-sm">
                    @foreach ($data['payments']['methods'] as $method)
                        <div class="flex justify-between gap-3 py-1">
                            <dt class="truncate text-gray-700">{{ $method['label'] }}</dt>
                            <dd class="whitespace-nowrap font-semibold tabular {{ $methodText[$method['method']] ?? 'text-gray-900' }}">{{ $money($method['amount']) }}</dd>
                        </div>
                    @endforeach
                    <div class="flex justify-between gap-3 border-t border-gray-200 pt-1.5 font-semibold">
                        <dt>Total</dt>
                        <dd class="whitespace-nowrap tabular">{{ $money($data['payments']['total']) }}</dd>
                    </div>
                </dl>
            </x-dashboard.panel>
        @endisset

        @isset($data['stock'])
            <x-dashboard.panel title="Low stock items" :count="$data['stock']['low']" title-class="text-red-700" :href="route('reports.show', 'reorder-list')">
                @if ($data['stock']['low_items'] === [])
                    <p class="py-1 text-sm text-gray-500">Nothing at or below its reorder level.</p>
                @else
                    <ol class="divide-y divide-gray-100 text-sm">
                        @foreach ($data['stock']['low_items'] as $item)
                            <li class="flex justify-between gap-3 py-1">
                                <a href="{{ route('catalog.products.show', $item['id']) }}" class="min-w-0 truncate hover:underline" title="{{ $item['name'] }}">{{ $loop->iteration }}. {{ $item['name'] }}</a>
                                <span class="shrink-0 text-xs tabular text-gray-600">Stock: <b class="text-red-700">{{ $qty($item['on_hand']) }}</b></span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-dashboard.panel>
        @endisset

        @isset($data['bestToday'])
            <x-dashboard.panel title="Best selling items (today)" :href="route('reports.show', ['report' => 'sales-by-item', 'from' => $today, 'to' => $today])">
                @if ($data['bestToday'] === [])
                    <p class="py-1 text-sm text-gray-500">No sales settled yet today.</p>
                @else
                    <ol class="divide-y divide-gray-100 text-sm">
                        @foreach ($data['bestToday'] as $item)
                            <li class="flex justify-between gap-3 py-1">
                                <span class="min-w-0 truncate" title="{{ $item['name'] }}">{{ $loop->iteration }}. {{ $item['name'] }}</span>
                                <span class="shrink-0 text-xs tabular text-gray-600">{{ $item['qty'] }} {{ $item['unit'] }}</span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-dashboard.panel>
        @endisset

        @isset($data['sales'])
            <x-dashboard.panel title="Sales by counter" :href="route('reports.show', 'sales-by-counter')">
                @if ($data['sales']['counters'] === [])
                    <p class="py-1 text-sm text-gray-500">No sales settled yet today.</p>
                @else
                    <x-dashboard.donut caption="Net sales by counter today" :segments="array_map(fn ($counter) => ['label' => $counter['name'], 'value' => (float) $counter['net'], 'display' => $money($counter['net'])], $data['sales']['counters'])" class="py-1" />
                @endif
            </x-dashboard.panel>
        @endisset

        @isset($data['purchasing'])
            <x-dashboard.panel title="Pending purchases" :count="$data['purchasing']['to_approve'] + $data['purchasing']['to_receive']" count-color="amber" title-class="text-amber-700" :href="route('purchasing.purchase-orders.index')">
                @if ($data['purchasing']['open'] === [])
                    <p class="py-1 text-sm text-gray-500">No purchase orders waiting.</p>
                @else
                    <ul class="divide-y divide-gray-100 text-sm">
                        @foreach ($data['purchasing']['open'] as $order)
                            <li class="flex items-center gap-2 py-1" title="{{ $order['supplier'] }} · {{ $order['status'] }}">
                                <a href="{{ route('purchasing.purchase-orders.show', $order['id']) }}" class="shrink-0 font-medium hover:underline">{{ $order['number'] }}</a>
                                <span class="min-w-0 flex-1 truncate text-xs text-gray-500">{{ $order['supplier'] }}</span>
                                @if ($user->can('catalog.cost.view'))
                                    <span class="shrink-0 tabular">{{ number_format((float) $order['total']) }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-dashboard.panel>
        @endisset
    </div>

    {{-- Row 3: recent sales, supplier bills, cash --}}
    @if (isset($data['recentSales']) || isset($data['supplierDues']) || isset($data['drawer']))
        <div class="mt-2.5 grid items-stretch gap-2.5 lg:grid-cols-2 xl:grid-cols-12 [&>*]:min-w-0">
            @isset($data['recentSales'])
                <x-dashboard.panel title="Recent sales" :href="route('sales.index')" class="xl:col-span-4">
                    @if ($data['recentSales'] === [])
                        <p class="py-1 text-sm text-gray-500">No sales settled yet.</p>
                    @else
                        <div class="-mx-3.5 overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 text-left text-xs font-medium text-gray-500">
                                    <tr><th class="px-3.5 py-1">Invoice</th><th class="hidden px-2 py-1 2xl:table-cell">Time</th><th class="px-2 py-1">Customer</th><th class="px-2 py-1 text-right">Amount</th><th class="px-3.5 py-1">Payment</th></tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($data['recentSales'] as $sale)
                                        <tr>
                                            <td class="whitespace-nowrap px-3.5 py-1"><a href="{{ route('sales.show', $sale['id']) }}" class="font-medium hover:underline" title="{{ $sale['time'] }} · {{ $sale['counter'] }}">{{ $sale['invoice'] }}</a></td>
                                            <td class="hidden whitespace-nowrap px-2 py-1 tabular text-gray-600 2xl:table-cell">{{ $sale['time'] }}</td>
                                            <td class="max-w-28 truncate px-2 py-1 text-gray-700">{{ $sale['customer'] }}</td>
                                            <td class="whitespace-nowrap px-2 py-1 text-right tabular">{{ number_format((float) $sale['total'], 2) }}</td>
                                            <td class="whitespace-nowrap px-3.5 py-1"><x-ui.badge :color="$methodColors[$sale['method']] ?? 'gray'">{{ $methodShort[$sale['method']] ?? $sale['method_label'] }}</x-ui.badge></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    @isset($data['balances'])
                        <x-slot:footer>
                            <a href="{{ route('reports.show', 'receivables-ageing') }}" class="text-xs text-gray-600 hover:underline">Customers owe: <b class="tabular text-gray-900">{{ $money($data['balances']['receivable']) }}</b></a>
                        </x-slot:footer>
                    @endisset
                </x-dashboard.panel>
            @endisset

            @isset($data['supplierDues'])
                <x-dashboard.panel title="Pending payments (suppliers)" :href="route('reports.show', 'supplier-ageing')" class="xl:col-span-5">
                    @if ($data['supplierDues']['bills'] === [])
                        <p class="py-1 text-sm text-gray-500">No unpaid supplier bills.</p>
                    @else
                        <div class="-mx-3.5 overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 text-left text-xs font-medium text-gray-500">
                                    <tr><th class="px-3.5 py-1">Supplier</th><th class="hidden px-2 py-1 2xl:table-cell">Invoice</th><th class="px-2 py-1">Due</th><th class="px-2 py-1 text-right">Balance</th><th class="px-3.5 py-1">Status</th></tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($data['supplierDues']['bills'] as $bill)
                                        <tr>
                                            <td class="max-w-36 truncate px-3.5 py-1"><a href="{{ route('purchasing.goods-receipts.show', $bill['id']) }}" class="font-medium hover:underline" title="{{ $bill['invoice'] }} · total {{ number_format((float) $bill['total'], 2) }} · paid {{ number_format((float) $bill['paid'], 2) }}">{{ $bill['supplier'] }}</a></td>
                                            <td class="hidden whitespace-nowrap px-2 py-1 text-gray-600 2xl:table-cell">{{ $bill['invoice'] }}</td>
                                            <td class="whitespace-nowrap px-2 py-1 tabular text-gray-600">{{ \Illuminate\Support\Carbon::parse($bill['due'])->format('j M') }}</td>
                                            <td class="whitespace-nowrap px-2 py-1 text-right font-medium tabular">{{ number_format((float) $bill['balance'], 2) }}</td>
                                            <td class="whitespace-nowrap px-3.5 py-1">
                                                @switch($bill['status'])
                                                    @case('overdue') <x-ui.badge color="red">Overdue</x-ui.badge> @break
                                                    @case('due_soon') <x-ui.badge color="amber">Due soon</x-ui.badge> @break
                                                    @default <x-ui.badge color="gray">Not due</x-ui.badge>
                                                @endswitch
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    <x-slot:footer>
                        <span class="text-xs text-brand-800">Total outstanding: <b class="text-sm tabular">{{ $money($data['supplierDues']['outstanding']) }}</b></span>
                    </x-slot:footer>
                </x-dashboard.panel>
            @endisset

            @isset($data['drawer'])
                <x-dashboard.panel title="Cash summary (today)" :href="route('reports.show', 'drawer-sessions')" class="xl:col-span-3">
                    @if ($data['drawer']['holder'])
                        <x-slot:actions><span class="text-xs text-gray-500">{{ $data['drawer']['holder'] }}</span></x-slot:actions>
                    @endif
                    @if ($data['drawer']['summary'] === null)
                        <p class="py-1 text-sm text-gray-500">The main cash drawer is closed.</p>
                    @else
                        @php $cash = $data['drawer']['summary']; @endphp
                        <dl class="text-sm">
                            <div class="flex justify-between gap-3 border-b border-gray-100 py-1"><dt class="text-gray-700">Opening cash</dt><dd class="whitespace-nowrap font-semibold tabular">{{ $money($cash['opening']) }}</dd></div>
                            <div class="flex justify-between gap-3 border-b border-gray-100 py-1"><dt class="text-gray-700">Cash sales</dt><dd class="whitespace-nowrap font-semibold tabular">{{ $money($cash['sales']) }}</dd></div>
                            <div class="flex justify-between gap-3 border-b border-gray-100 py-1"><dt class="text-gray-700">Cash in (other)</dt><dd class="whitespace-nowrap font-semibold tabular">{{ $money($cash['cash_in']) }}</dd></div>
                            <div class="flex justify-between gap-3 py-1"><dt class="text-gray-700">Cash out</dt><dd class="whitespace-nowrap font-semibold tabular text-red-700">{{ $money($cash['cash_out']) }}</dd></div>
                            <div class="-mx-2 mt-1 flex justify-between gap-3 rounded-md bg-brand-50 px-2 py-2 font-semibold text-brand-900 ring-1 ring-brand-200"><dt>Closing cash <span class="hidden 2xl:inline">(expected)</span></dt><dd class="whitespace-nowrap tabular">{{ $money($cash['expected']) }}</dd></div>
                        </dl>
                    @endif
                </x-dashboard.panel>
            @endisset
        </div>
    @endif

    {{-- Row 4: quick actions --}}
    @php
        $actions = array_values(array_filter([
            $terminal && $user->can('pos.sell') ? ['route' => 'pos.counter', 'icon' => 'cart', 'label' => 'New sale (POS)', 'short' => 'New sale', 'accent' => 'green'] : null,
            $terminal?->isMainCashier() && $user->canAny(['pos.settle', 'drawer.manage']) ? ['route' => 'pos.cashier', 'icon' => 'banknotes', 'label' => 'Cashier', 'accent' => 'green'] : null,
            $user->can('pos.live_view') ? ['route' => 'admin.live-billing', 'icon' => 'screen', 'label' => 'Live billing', 'accent' => 'teal'] : null,
            $user->can('create', PurchaseOrder::class) ? ['route' => 'purchasing.purchase-orders.create', 'icon' => 'truck', 'label' => 'New purchase order', 'short' => 'New PO', 'accent' => 'blue'] : null,
            $user->can('create', GoodsReceipt::class) ? ['route' => 'purchasing.goods-receipts.create', 'icon' => 'inbox-in', 'label' => 'Goods received', 'short' => 'Receive goods', 'accent' => 'violet'] : null,
            $user->can('create', Expense::class) ? ['route' => 'finance.expenses.create', 'icon' => 'receipt', 'label' => 'Add expense', 'accent' => 'amber'] : null,
            $user->can('create', SupplierPayment::class) ? ['route' => 'purchasing.supplier-payments.create', 'icon' => 'banknotes', 'label' => 'Supplier payment', 'short' => 'Pay supplier', 'accent' => 'green'] : null,
            $user->can('create', PackOpening::class) ? ['route' => 'inventory.pack-openings.create', 'icon' => 'box-open', 'label' => 'Open packs', 'accent' => 'blue'] : null,
            $user->can('create', Stocktake::class) ? ['route' => 'inventory.stocktakes.create', 'icon' => 'clipboard-check', 'label' => 'Stock count', 'accent' => 'gray'] : null,
            $user->canAny(['reports.sales', 'reports.inventory', 'reports.finance']) ? ['route' => 'reports.index', 'icon' => 'chart', 'label' => 'Reports', 'accent' => 'red'] : null,
        ]));
    @endphp
    @if ($actions)
        <section class="mt-2.5 flex flex-col gap-2 rounded-lg bg-white px-3 py-2 shadow-sm ring-1 ring-gray-200 lg:flex-row lg:items-center lg:gap-4">
            <h2 class="shrink-0 text-xs font-semibold uppercase tracking-wide text-gray-900 lg:hidden 2xl:block">Quick actions</h2>
            <div class="grid flex-1 grid-cols-[repeat(auto-fill,minmax(7.5rem,1fr))] gap-2">
                @foreach ($actions as $action)
                    <x-dashboard.quick-action :href="route($action['route'])" :icon="$action['icon']" :label="$action['label']" :short="$action['short'] ?? null" :accent="$action['accent']" />
                @endforeach
            </div>
        </section>
    @endif
@endsection
