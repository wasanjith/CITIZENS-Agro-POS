@extends('layouts.app')

@php
    use App\Domain\Inventory\Support\Qty;
    $money = fn ($value) => number_format((float) (string) $value, 2);
@endphp

@section('title', 'Sale return')

@section('content')
    <x-ui.page-header title="Sale return" description="Goods brought back against an invoice. The money comes from your drawer or goes to the customer's account.">
        <x-ui.button variant="secondary" :href="route('pos.cashier')">Back to cashier</x-ui.button>
    </x-ui.page-header>

    @foreach (['sale', 'drawer', 'lines', 'refund_method', 'reason'] as $field)
        @error($field)<x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>@enderror
    @endforeach

    <form method="GET" action="{{ route('pos.returns.create') }}" class="mb-6 flex max-w-xl items-end gap-2">
        <x-ui.input name="invoice" label="Invoice number" :value="request('invoice', $sale?->invoice_no)" placeholder="Last digits, e.g. 4512" inputmode="numeric" autofocus class="flex-1" />
        <x-ui.button type="submit">Find</x-ui.button>
    </form>

    @if ($notFound)
        <x-ui.alert type="warning" class="mb-4">{{ $notFound }}</x-ui.alert>
    @endif

    @if ($sale)
        @if (! $sale->canBeReturned())
            <x-ui.alert type="warning" class="mb-4">{{ $sale->invoice_no }} is {{ strtolower($sale->status->label()) }}. Only settled invoices can take returns.@if ($sale->isInvoiced()) Void it instead.@endif</x-ui.alert>
        @else
            @php
                $isCredit = $sale->isCreditSale() && (float) $sale->balance_due > 0;
                $defaultMethod = old('refund_method', $sale->customer_id && $isCredit ? 'account' : 'cash');
            @endphp
            <form method="POST" action="{{ route('pos.returns.store', $sale) }}" x-data="{ busy: false }" @submit="busy = true">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

                <x-ui.card :title="$sale->invoice_no" :description="$sale->invoicedTerminal->displayName().' · '.$sale->invoiced_at?->format('Y-m-d H:i').' · '.$sale->payment_method_intent->label().($sale->customer ? ' · '.$sale->customer->name : '').' · total Rs. '.$money($sale->total)">
                    <x-ui.table class="shadow-none ring-0">
                        <x-slot:head>
                            <th>Item</th>
                            <th class="text-right">Sold</th>
                            <th class="text-right">Returned before</th>
                            <th class="text-right">Value left</th>
                            <th>Coming back</th>
                            <th>Back on the shelf</th>
                        </x-slot:head>
                        @foreach ($rows as $itemId => $row)
                            @php $item = $row['item']; $none = ! $row['returnable_base']->isPositive(); @endphp
                            <tr @class(['opacity-50' => $none])>
                                <td>
                                    <span class="font-mono text-xs font-semibold text-brand-700">{{ $item->short_code_snapshot }}</span>
                                    <span class="font-medium">{{ $item->name_snapshot }}</span>
                                    @if ($item->name_si_snapshot)<span class="block font-sinhala text-xs text-gray-500">{{ $item->name_si_snapshot }}</span>@endif
                                </td>
                                <td class="text-right tabular">{{ Qty::format($item->qty) }} {{ $item->unit_snapshot }}</td>
                                <td class="text-right tabular">{{ $row['returned_qty']->isZero() ? '' : Qty::format((string) $row['returned_qty']).' '.$item->unit_snapshot }}</td>
                                <td class="text-right tabular">{{ $money($row['refundable']) }}</td>
                                <td>
                                    @unless ($none)
                                        <div class="flex items-center gap-1">
                                            <input type="text" inputmode="decimal" name="lines[{{ $itemId }}][qty]" value="{{ old('lines.'.$itemId.'.qty') }}" placeholder="0" class="w-20 rounded-md border-gray-300 text-right text-sm tabular" aria-label="Quantity of {{ $item->name_snapshot }} coming back">
                                            <span class="text-xs text-gray-500">of {{ Qty::format((string) $row['returnable_qty']) }} {{ $item->unit_snapshot }}</span>
                                        </div>
                                        @error('lines.'.$itemId.'.qty')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                                    @endunless
                                </td>
                                <td>
                                    @unless ($none)
                                        <input type="hidden" name="lines[{{ $itemId }}][restock]" value="0">
                                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="lines[{{ $itemId }}][restock]" value="1" @checked(old('lines.'.$itemId.'.restock', '1') === '1') class="rounded text-brand-600"> Restock</label>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                    <p class="mt-2 text-xs text-gray-500">Untick "Restock" for damaged goods: they come back into stock and are written off as damage straight away. Stock goes back to the batches it was sold from. The refund of each line is its share of the invoice, after discounts.</p>

                    <div class="mt-6 grid gap-4 sm:grid-cols-2">
                        <x-ui.input name="reason" label="Reason" required maxlength="255" placeholder="Wrong item, damaged, not needed …" />
                        <div>
                            <x-ui.label for="refund_method" required>Refund</x-ui.label>
                            <select id="refund_method" name="refund_method" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                @foreach ($methods as $value => $label)
                                    <option value="{{ $value }}" @selected($defaultMethod === $value) @disabled(($value === 'account' && ! $sale->customer_id) || ($value === 'cash' && $isCredit))>{{ $label }}</option>
                                @endforeach
                            </select>
                            @if ($isCredit)
                                <p class="mt-1 text-xs text-gray-500">Credit invoice with Rs. {{ $money($sale->balance_due) }} still owed: the refund lowers what the customer owes.</p>
                            @elseif (! $sale->customer_id)
                                <p class="mt-1 text-xs text-gray-500">No customer on this invoice: refund in cash.</p>
                            @endif
                        </div>
                    </div>

                    <x-slot:footer>
                        <x-ui.button variant="secondary" :href="route('sales.show', $sale)">Open invoice</x-ui.button>
                        <x-ui.button type="submit" x-bind:disabled="busy">Take return and print receipt</x-ui.button>
                    </x-slot:footer>
                </x-ui.card>
            </form>
        @endif
    @endif
@endsection
