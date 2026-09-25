@extends('layouts.app')

@php
    use App\Domain\Inventory\Support\Qty;
    use App\Domain\Sales\Enums\QuotationStatus;
    $money = fn ($value) => number_format((float) $value, 2);
    $status = $quotation->effectiveStatus();
@endphp

@section('title', $quotation->number)

@section('content')
    <x-ui.page-header :title="'Quotation '.$quotation->number" :description="$quotation->created_at->format('Y-m-d H:i').' · '.$quotation->creator->name.($quotation->terminal ? ' · '.$quotation->terminal->displayName() : '')">
        <x-ui.badge :color="$status->color()" class="text-sm">{{ $status->label() }}</x-ui.badge>
        <x-ui.button variant="secondary" :href="route('pos.quotations.print', $quotation)" target="_blank">80 mm</x-ui.button>
        <x-ui.button variant="secondary" :href="route('quotations.pdf', $quotation)">A4 PDF</x-ui.button>
        @if ($quotation->status === QuotationStatus::Open)
            @can('cancel', $quotation)
                <form method="POST" action="{{ route('quotations.cancel', $quotation) }}">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" class="text-red-700">Cancel quotation</x-ui.button>
                </form>
            @endcan
        @endif
    </x-ui.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Items" class="lg:col-span-2">
            <x-ui.table class="shadow-none ring-0">
                <x-slot:head>
                    <th>Item</th>
                    <th class="text-right">Qty</th>
                    <th class="text-right">Price</th>
                    <th class="text-right">Discount</th>
                    <th class="text-right">Amount</th>
                </x-slot:head>
                @foreach ($quotation->lines as $line)
                    <tr>
                        <td>
                            <span class="font-mono text-xs font-semibold text-brand-700">{{ $line->short_code_snapshot }}</span>
                            <span class="font-medium">{{ $line->name_snapshot }}</span>
                            @if ($line->name_si_snapshot)<span class="block font-sinhala text-xs text-gray-500">{{ $line->name_si_snapshot }}</span>@endif
                        </td>
                        <td class="text-right tabular">{{ Qty::format($line->qty) }} {{ $line->unit_snapshot }}</td>
                        <td class="text-right tabular">{{ $money($line->unit_price) }}</td>
                        <td class="text-right tabular">{{ (float) $line->discount_amount > 0 ? '-'.$money($line->discount_amount) : '' }}</td>
                        <td class="text-right tabular font-medium">{{ $money($line->line_total) }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="Totals">
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between"><dt>Subtotal</dt><dd class="tabular">{{ $money($quotation->subtotal) }}</dd></div>
                <div class="flex justify-between"><dt>Discounts</dt><dd class="tabular">-{{ $money((float) $quotation->line_discount_total + (float) $quotation->bill_discount) }}</dd></div>
                <div class="flex justify-between border-t border-gray-200 pt-1 text-base font-semibold"><dt>Total</dt><dd class="tabular">{{ $money($quotation->total) }}</dd></div>
                <div class="flex justify-between text-gray-600"><dt>Valid until</dt><dd>{{ $quotation->valid_until->format('Y-m-d') }}</dd></div>
                <div class="flex justify-between text-gray-600"><dt>Price list</dt><dd>{{ $quotation->priceList->name }}</dd></div>
                <div class="flex justify-between text-gray-600"><dt>Customer</dt><dd>
                    @if ($quotation->customer)<a href="{{ route('customers.show', $quotation->customer) }}" class="text-brand-700 hover:underline">{{ $quotation->customer->name }}</a>@else{{ $quotation->customer_name ?? '—' }}@endif
                </dd></div>
                @if ($quotation->convertedSale)
                    <div class="flex justify-between"><dt>Billed as</dt><dd><a href="{{ route('sales.show', $quotation->convertedSale) }}" class="font-mono text-brand-700 hover:underline">{{ $quotation->convertedSale->invoice_no }}</a></dd></div>
                @endif
                @if ($quotation->note)<div class="pt-2 text-gray-600">{{ $quotation->note }}</div>@endif
            </dl>
            <p class="mt-4 text-xs text-gray-500">To bill it: on the counter screen press F7 → Quotations → Load. Prices are worked out again when the invoice prints.</p>
        </x-ui.card>
    </div>
@endsection
