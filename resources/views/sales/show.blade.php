@extends('layouts.app')

@php
    use App\Domain\Inventory\Support\Qty;
    use App\Domain\Sales\Enums\SaleStatus;
    $money = fn ($value) => number_format((float) $value, 2);
@endphp

@section('title', $sale->invoice_no ?? 'Held bill')

@section('content')
    <x-ui.page-header :title="'Invoice '.($sale->invoice_no ?? '(held)')" :description="$sale->invoicedTerminal->displayName().' · '.$sale->invoicedBy->name.' · '.($sale->invoiced_at?->format('Y-m-d H:i') ?? '')">
        <x-ui.badge :color="$sale->status->color()" class="text-sm">{{ $sale->status->label() }}</x-ui.badge>
        @if ($sale->invoice_no)
            <x-ui.button variant="secondary" :href="route('pos.sales.invoice', $sale)" target="_blank">80 mm</x-ui.button>
            <x-ui.button variant="secondary" :href="route('pos.sales.invoice-pdf', $sale)">A4 PDF</x-ui.button>
        @endif
        @if ($canVoidHere)
            <x-ui.button variant="danger" x-data x-on:click="$dispatch('open-modal', 'void-sale')">Void</x-ui.button>
        @endif
    </x-ui.page-header>

    @foreach (['sale', 'drawer', 'reason'] as $field)
        @error($field)<x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>@enderror
    @endforeach

    @if ($sale->status === SaleStatus::Void)
        <x-ui.alert type="warning" class="mb-4" title="Voided by {{ $sale->voidedBy?->name }} at {{ $sale->voided_at?->format('Y-m-d H:i') }}">{{ $sale->void_reason }}</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Items" class="lg:col-span-2">
            <x-ui.table class="shadow-none ring-0">
                <x-slot:head>
                    <th>Item</th>
                    <th class="text-right">Qty</th>
                    <th class="text-right">Price</th>
                    <th class="text-right">Discount</th>
                    <th class="text-right">Amount</th>
                    @if ($showCost)<th class="text-right">Cost</th>@endif
                </x-slot:head>
                @foreach ($sale->items as $item)
                    <tr>
                        <td>
                            <span class="font-mono text-xs font-semibold text-brand-700">{{ $item->short_code_snapshot }}</span>
                            <span class="font-medium">{{ $item->name_snapshot }}</span>
                            @if ($item->name_si_snapshot)<span class="block font-sinhala text-xs text-gray-500">{{ $item->name_si_snapshot }}</span>@endif
                            @if ($showCost && $item->batches->isNotEmpty())
                                <span class="block text-xs text-gray-500">
                                    @foreach ($item->batches as $allocation){{ $allocation->batch->label() }}: {{ Qty::format($allocation->base_qty) }}@if (! $loop->last); @endif @endforeach
                                </span>
                            @endif
                        </td>
                        <td class="text-right tabular">{{ Qty::format($item->qty) }} {{ $item->unit_snapshot }}</td>
                        <td class="text-right tabular">{{ $money($item->unit_price) }}</td>
                        <td class="text-right tabular">{{ (float) $item->discount_amount > 0 ? '-'.$money($item->discount_amount) : '' }}</td>
                        <td class="text-right tabular font-medium">{{ $money($item->line_total) }}</td>
                        @if ($showCost)<td class="text-right tabular text-gray-600">{{ $item->cost_total !== null ? $money($item->cost_total) : '—' }}</td>@endif
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <div class="space-y-6">
            <x-ui.card title="Totals">
                <dl class="space-y-1 text-sm">
                    <div class="flex justify-between"><dt>Subtotal</dt><dd class="tabular">{{ $money($sale->subtotal) }}</dd></div>
                    <div class="flex justify-between"><dt>Line discounts</dt><dd class="tabular">-{{ $money($sale->line_discount_total) }}</dd></div>
                    <div class="flex justify-between"><dt>Bill discount</dt><dd class="tabular">-{{ $money($sale->bill_discount) }}</dd></div>
                    <div class="flex justify-between border-t border-gray-200 pt-1 text-base font-semibold"><dt>Total</dt><dd class="tabular">{{ $money($sale->total) }}</dd></div>
                    <div class="flex justify-between"><dt>{{ $sale->payment_method_intent->label() }} tendered</dt><dd class="tabular">{{ $sale->tendered_amount !== null ? $money($sale->tendered_amount) : '—' }}</dd></div>
                    <div class="flex justify-between"><dt>Balance given</dt><dd class="tabular">{{ $money($sale->change_due) }}</dd></div>
                    @if ($showCost && $sale->cost_total !== null)
                        <div class="flex justify-between text-gray-600"><dt>Cost (FEFO batches)</dt><dd class="tabular">{{ $money($sale->cost_total) }}</dd></div>
                        <div class="flex justify-between text-gray-600"><dt>Gross profit</dt><dd class="tabular">{{ $money((float) $sale->total - (float) $sale->cost_total) }}</dd></div>
                    @endif
                    <div class="flex justify-between text-gray-500"><dt>Printed</dt><dd>{{ $sale->print_count }}×</dd></div>
                </dl>
            </x-ui.card>

            <x-ui.card title="Payments">
                @forelse ($sale->payments as $payment)
                    <div class="flex justify-between py-1 text-sm">
                        <span>{{ $payment->method->label() }} @if ($payment->reference)<span class="text-gray-500">· {{ $payment->reference }}</span>@endif <span class="block text-xs text-gray-500">{{ $payment->created_at->format('H:i') }} · {{ $payment->confirmedBy->name }}</span></span>
                        <span @class(['tabular', 'text-red-700' => (float) $payment->amount < 0])>{{ $money($payment->amount) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">Not settled yet.</p>
                @endforelse
            </x-ui.card>

            <x-ui.card title="Trail">
                <ul class="space-y-1 text-xs">
                    @forelse ($events as $event)
                        <li @class(['text-red-700' => $event->type->isAlert(), 'text-gray-600' => ! $event->type->isAlert()])>
                            <span class="tabular text-gray-400">{{ $event->created_at->format('H:i:s') }}</span> {{ $event->user?->name }} {{ $event->summary() }}
                        </li>
                    @empty
                        <li class="text-gray-500">No events.</li>
                    @endforelse
                </ul>
            </x-ui.card>
        </div>
    </div>

    @if ($canVoidHere)
        <x-ui.modal name="void-sale" :title="'Void '.$sale->invoice_no" max-width="md" :show="$errors->has('reason')">
            <p class="mb-3 text-sm text-gray-600">
                @if ($sale->status === SaleStatus::Settled)
                    The stock goes back and Rs. {{ $money($sale->total) }} is refunded from your drawer. Only on the main cashier terminal, for sales settled today.
                @else
                    The stock reservation is released and the counter is offered the bill back.
                @endif
            </p>
            <form method="POST" action="{{ route('pos.sales.void', $sale) }}" id="void-form">
                @csrf
                <x-ui.input name="reason" label="Reason" required maxlength="255" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'void-sale')">Cancel</x-ui.button>
                <x-ui.button type="submit" variant="danger" form="void-form">Void invoice</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
@endsection
