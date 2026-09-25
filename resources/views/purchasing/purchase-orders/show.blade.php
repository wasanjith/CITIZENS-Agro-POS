@extends('layouts.app')

@php
    use App\Domain\Inventory\Support\Qty;
    use App\Domain\Purchasing\Enums\PurchaseOrderStatus;

    $inputClass = 'block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500';
    $canApprove = auth()->user()->can('approve', $order);
    $canSend = auth()->user()->can('send', $order);
    $whatsappNumber = $order->supplier->whatsappNumber();
    $shareUrl = $canSend ? \App\Http\Controllers\Purchasing\PurchaseOrderController::sharedPdfUrl($order) : null;
    $shareText = $shareUrl ? "Purchase order {$order->number} from ".config('app.name').": {$shareUrl}" : null;
@endphp

@section('title', 'Purchase order '.$order->number)

@section('content')
    <x-ui.page-header :title="'Purchase order '.$order->number" :description="$order->supplier->name">
        <x-ui.badge :color="$order->status->color()" class="text-sm">{{ $order->status->label() }}</x-ui.badge>

        @can('update', $order)
            <x-ui.button variant="secondary" :href="route('purchasing.purchase-orders.edit', $order)">Edit</x-ui.button>
        @endcan
        @can('submit', $order)
            <form method="POST" action="{{ route('purchasing.purchase-orders.submit', $order) }}">
                @csrf
                <x-ui.button type="submit">Submit for approval</x-ui.button>
            </form>
        @endcan
        @if ($canSend)
            <x-ui.button variant="secondary" :href="route('purchasing.purchase-orders.pdf', $order)">Download PDF</x-ui.button>
            @if ($whatsappNumber && in_array($order->status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::Sent], true))
                <form method="POST" action="{{ route('purchasing.purchase-orders.send', $order) }}" x-data x-ref="sent">
                    @csrf
                    <x-ui.button
                        variant="secondary"
                        :href="'https://wa.me/'.$whatsappNumber.'?text='.rawurlencode($shareText)"
                        target="_blank"
                        rel="noopener"
                        x-on:click="setTimeout(() => $refs.sent.submit(), 300)"
                    >Send by WhatsApp</x-ui.button>
                </form>
            @endif
            @if ($order->status === PurchaseOrderStatus::Approved)
                <form method="POST" action="{{ route('purchasing.purchase-orders.send', $order) }}">
                    @csrf
                    <x-ui.button type="submit" variant="secondary">Mark as sent</x-ui.button>
                </form>
            @endif
        @endif
        @can('receive', $order)
            <x-ui.button :href="route('purchasing.goods-receipts.create', ['purchase_order' => $order->id])">Receive goods</x-ui.button>
        @endcan
        @can('close', $order)
            <x-ui.button variant="secondary" x-data x-on:click="$dispatch('open-modal', 'close-po')">Close</x-ui.button>
            <x-ui.confirm-modal name="close-po" :action="route('purchasing.purchase-orders.close', $order)" title="Close {{ $order->number }}?" confirm="Close order" variant="primary">
                Nothing more will be received on this order. Use this when the supplier will not deliver the rest.
            </x-ui.confirm-modal>
        @endcan
        @can('cancel', $order)
            <x-ui.button variant="secondary" class="text-red-700" x-data x-on:click="$dispatch('open-modal', 'cancel-po')">Cancel order</x-ui.button>
            <x-ui.confirm-modal name="cancel-po" :action="route('purchasing.purchase-orders.cancel', $order)" title="Cancel {{ $order->number }}?" confirm="Cancel order">
                The order stays on record as cancelled.
            </x-ui.confirm-modal>
        @endcan
    </x-ui.page-header>

    @error('status')
        <x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror
    @error('lines')
        <x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    @if ($order->status === PurchaseOrderStatus::Rejected)
        <x-ui.alert type="warning" title="Rejected by {{ $order->approver?->name }}" class="mb-4">{{ $order->rejected_reason }}</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <form method="POST" action="{{ route('purchasing.purchase-orders.approve', $order) }}" x-data="{ costs: @js($suggestedCosts), discount: @js((string) old('discount', (float) $order->discount ? $order->discount : '')), tax: @js((string) old('tax', (float) $order->tax ? $order->tax : '')) }">
                @csrf
                <x-ui.card title="Products">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                                <tr>
                                    <th class="py-2 pr-3">Product</th>
                                    <th class="py-2 pr-3 text-right">In stock</th>
                                    <th class="py-2 pr-3 text-right">Ordered</th>
                                    <th class="py-2 pr-3 text-right">Received</th>
                                    @if ($canCost)
                                        <th class="py-2 pr-3 text-right">Unit cost</th>
                                        <th class="py-2 text-right">Total</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($order->lines as $line)
                                    @php
                                        $factor = (string) \Brick\Math\BigDecimal::of($line->base_qty)->dividedBy($line->qty, 3, \Brick\Math\RoundingMode::HalfUp);
                                        $onHand = $stock[$line->product_id][$line->variant_id ?? 0]['available'] ?? '0';
                                    @endphp
                                    <tr class="align-top">
                                        <td class="py-2 pr-3">
                                            <span class="font-mono text-xs font-semibold text-brand-700">{{ $line->variant?->short_code ?? $line->product->short_code }}</span>
                                            <span class="font-medium">{{ $line->product->name }} {{ $line->variant?->name }}</span>
                                        </td>
                                        <td class="py-2 pr-3 text-right tabular whitespace-nowrap @if (\Brick\Math\BigDecimal::of($onHand)->isLessThanOrEqualTo($line->product->reorder_level)) font-semibold text-amber-700 @endif">
                                            {{ Qty::format($onHand) }} {{ $line->product->baseUnit?->symbol }}
                                        </td>
                                        <td class="py-2 pr-3 text-right tabular whitespace-nowrap">{{ Qty::format($line->qty) }} {{ $line->unit->symbol }}</td>
                                        <td class="py-2 pr-3 text-right tabular whitespace-nowrap">
                                            {{ Qty::inUnit($line->received_base_qty, $factor, $line->unit->symbol) }}
                                        </td>
                                        @if ($canCost)
                                            @if ($canApprove)
                                                <td class="py-2 pr-3">
                                                    <input type="text" inputmode="decimal" name="costs[{{ $line->id }}]" x-model="costs[{{ $line->id }}]" required placeholder="0.00" class="{{ $inputClass }} ml-auto w-32 text-right tabular" aria-label="Unit cost of {{ $line->product->name }}">
                                                    <x-ui.field-error name="costs.{{ $line->id }}" />
                                                </td>
                                                <td class="py-2 text-right tabular whitespace-nowrap" x-text="(Math.round(Number(costs[{{ $line->id }}] || 0) * {{ (float) $line->qty }} * 100) / 100).toLocaleString('en-LK', { minimumFractionDigits: 2 })"></td>
                                            @else
                                                <td class="py-2 pr-3 text-right tabular">{{ $line->unit_cost !== null ? number_format((float) $line->unit_cost, 2) : '—' }}</td>
                                                <td class="py-2 text-right tabular">{{ number_format((float) $line->line_total, 2) }}</td>
                                            @endif
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($canCost)
                        <div class="mt-6 flex justify-end">
                            <dl class="w-full max-w-xs space-y-2 text-sm">
                                @if ($canApprove)
                                    <div class="flex items-center justify-between gap-3">
                                        <dt><label for="discount" class="text-gray-500">Discount</label></dt>
                                        <dd><input type="text" inputmode="decimal" id="discount" name="discount" x-model="discount" placeholder="0.00" class="{{ $inputClass }} w-32 text-right tabular"></dd>
                                    </div>
                                    <div class="flex items-center justify-between gap-3">
                                        <dt><label for="tax" class="text-gray-500">Tax</label></dt>
                                        <dd><input type="text" inputmode="decimal" id="tax" name="tax" x-model="tax" placeholder="0.00" class="{{ $inputClass }} w-32 text-right tabular"></dd>
                                    </div>
                                @else
                                    <div class="flex justify-between"><dt class="text-gray-500">Subtotal</dt><dd class="tabular">{{ number_format((float) $order->subtotal, 2) }}</dd></div>
                                    <div class="flex justify-between"><dt class="text-gray-500">Discount</dt><dd class="tabular">{{ number_format((float) $order->discount, 2) }}</dd></div>
                                    <div class="flex justify-between"><dt class="text-gray-500">Tax</dt><dd class="tabular">{{ number_format((float) $order->tax, 2) }}</dd></div>
                                    <div class="flex justify-between border-t border-gray-200 pt-2 text-base font-semibold"><dt>Total</dt><dd class="tabular">Rs. {{ number_format((float) $order->total, 2) }}</dd></div>
                                @endif
                            </dl>
                        </div>
                    @endif

                    @if ($canApprove)
                        <x-slot:footer>
                            <x-ui.button variant="secondary" class="text-red-700" x-on:click="$dispatch('open-modal', 'reject-po')">Reject</x-ui.button>
                            <x-ui.button type="submit">Approve order</x-ui.button>
                        </x-slot:footer>
                    @endif
                </x-ui.card>
            </form>

            @if ($canApprove)
                <x-ui.modal name="reject-po" title="Reject {{ $order->number }}" max-width="md" :show="$errors->has('reason')">
                    <form method="POST" action="{{ route('purchasing.purchase-orders.reject', $order) }}" id="reject-form">
                        @csrf
                        <x-ui.textarea name="reason" label="Reason (shown to {{ $order->creator?->name ?? 'the creator' }})" rows="3" required />
                    </form>
                    <x-slot:footer>
                        <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'reject-po')">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="danger" form="reject-form">Reject order</x-ui.button>
                    </x-slot:footer>
                </x-ui.modal>
            @endif

            @if ($order->goodsReceipts->isNotEmpty())
                <x-ui.card title="Goods received">
                    <ul class="divide-y divide-gray-100 text-sm">
                        @foreach ($order->goodsReceipts as $receipt)
                            <li class="flex items-center justify-between gap-3 py-2">
                                @can('view', $receipt)
                                    <a href="{{ route('purchasing.goods-receipts.show', $receipt) }}" class="font-mono font-medium text-brand-700 hover:underline">{{ $receipt->number }}</a>
                                @else
                                    <span class="font-mono">{{ $receipt->number }}</span>
                                @endcan
                                <span class="text-gray-500">{{ $receipt->received_at->format('Y-m-d') }}</span>
                                <x-ui.badge :color="$receipt->status->color()">{{ $receipt->status->label() }}</x-ui.badge>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-6">
            <x-ui.card title="Details">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Supplier</dt><dd class="text-right">{{ $order->supplier->name }}<br><span class="text-xs text-gray-500">{{ $order->supplier->phone }}</span></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Order date</dt><dd>{{ $order->order_date->format('Y-m-d') }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Expected</dt><dd>{{ $order->expected_date?->format('Y-m-d') ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Raised by</dt><dd>{{ $order->creator?->name ?? '—' }}</dd></div>
                    @if ($order->submitted_at)
                        <div class="flex justify-between gap-3"><dt class="text-gray-500">Submitted</dt><dd>{{ $order->submitted_at->format('Y-m-d H:i') }}</dd></div>
                    @endif
                    @if ($order->approved_at && $order->status !== PurchaseOrderStatus::Rejected)
                        <div class="flex justify-between gap-3"><dt class="text-gray-500">Approved</dt><dd class="text-right">{{ $order->approver?->name }}<br><span class="text-xs text-gray-500">{{ $order->approved_at->format('Y-m-d H:i') }}</span></dd></div>
                    @endif
                    @if ($order->sent_at)
                        <div class="flex justify-between gap-3"><dt class="text-gray-500">Sent</dt><dd>{{ $order->sent_at->format('Y-m-d H:i') }}</dd></div>
                    @endif
                </dl>
                @if ($order->note)
                    <p class="mt-4 whitespace-pre-line border-t border-gray-200 pt-4 text-sm text-gray-700">{{ $order->note }}</p>
                @endif
            </x-ui.card>

            @if ($canSend && ! $whatsappNumber)
                <x-ui.alert type="info">Add the supplier's phone number to send this order by WhatsApp.</x-ui.alert>
            @elseif ($canSend)
                <p class="text-xs text-gray-500">The WhatsApp message carries a link to the PDF, valid for 30 days. The supplier can open it only where this server is reachable.</p>
            @endif
        </div>
    </div>
@endsection
