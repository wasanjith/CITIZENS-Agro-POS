@extends('layouts.app')

@php
    use App\Domain\Inventory\Support\Qty;
    $showCost = auth()->user()->can('viewCost', \App\Domain\Catalog\Models\Product::class);
@endphp

@section('title', $adjustment->number)

@section('content')
    <x-ui.page-header :title="'Stock adjustment '.$adjustment->number" :description="$adjustment->reason->label()">
        <x-ui.badge :color="$adjustment->status->color()" class="text-sm">{{ $adjustment->status->label() }}</x-ui.badge>
        @can('approve', $adjustment)
            <x-ui.button variant="secondary" class="text-red-700" x-data x-on:click="$dispatch('open-modal', 'reject-adjustment')">Reject</x-ui.button>
            <form method="POST" action="{{ route('inventory.adjustments.approve', $adjustment) }}">
                @csrf
                <x-ui.button type="submit">Approve &amp; post</x-ui.button>
            </form>
            <x-ui.modal name="reject-adjustment" title="Reject {{ $adjustment->number }}" max-width="md" :show="$errors->has('rejection_reason')">
                <form method="POST" action="{{ route('inventory.adjustments.reject', $adjustment) }}" id="reject-form">
                    @csrf
                    <x-ui.textarea name="rejection_reason" label="Reason" rows="3" required />
                </form>
                <x-slot:footer>
                    <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'reject-adjustment')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="danger" form="reject-form">Reject</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endcan
    </x-ui.page-header>

    @foreach (['status', 'stock'] as $field)
        @error($field)
            <x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>
        @enderror
    @endforeach

    @if ($adjustment->status === \App\Domain\Inventory\Enums\AdjustmentStatus::Rejected)
        <x-ui.alert type="warning" title="Rejected by {{ $adjustment->approver?->name }}" class="mb-4">{{ $adjustment->rejection_reason }}</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Products" class="lg:col-span-2">
            <x-ui.table class="shadow-none ring-0">
                <x-slot:head>
                    <th>Product</th>
                    <th>Batch</th>
                    <th class="text-right">Change</th>
                    @if ($showCost)
                        <th class="text-right">Cost / unit</th>
                        <th class="text-right">Value</th>
                    @endif
                </x-slot:head>
                @foreach ($adjustment->lines as $line)
                    <tr>
                        <td>
                            <span class="font-mono text-xs font-semibold text-brand-700">{{ $line->variant?->short_code ?? $line->product->short_code }}</span>
                            <span class="font-medium">{{ $line->product->name }} {{ $line->variant?->name }}</span>
                        </td>
                        <td class="text-gray-600">{{ $line->batch?->label() ?? ((float) $line->qty > 0 ? 'General stock' : 'First to expire') }}</td>
                        <td @class(['text-right tabular font-medium whitespace-nowrap', 'text-brand-700' => (float) $line->qty > 0, 'text-red-700' => (float) $line->qty < 0])>
                            {{ (float) $line->qty > 0 ? '+' : '' }}{{ Qty::format($line->qty) }} {{ $line->product->baseUnit?->symbol }}
                        </td>
                        @if ($showCost)
                            <td class="text-right tabular text-gray-600">{{ number_format((float) $line->unit_cost, 2) }}</td>
                            <td class="text-right tabular">{{ number_format(abs((float) $line->qty) * (float) $line->unit_cost, 2) }}</td>
                        @endif
                    </tr>
                @endforeach
            </x-ui.table>
            <p class="mt-4 text-right text-base font-semibold">Value: Rs. {{ number_format((float) $adjustment->total_value, 2) }}</p>
        </x-ui.card>

        <x-ui.card title="Details">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-gray-500">Reason</dt><dd>{{ $adjustment->reason->label() }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">Created</dt><dd class="text-right">{{ $adjustment->creator?->name }}<br><span class="text-xs text-gray-500">{{ $adjustment->created_at?->format('Y-m-d H:i') }}</span></dd></div>
                @if ($adjustment->approved_at)
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-500">{{ $adjustment->status === \App\Domain\Inventory\Enums\AdjustmentStatus::Rejected ? 'Rejected' : 'Posted' }}</dt>
                        <dd class="text-right">{{ $adjustment->approver?->name }}<br><span class="text-xs text-gray-500">{{ $adjustment->approved_at->format('Y-m-d H:i') }}</span></dd>
                    </div>
                @endif
            </dl>
            @if ($adjustment->note)
                <p class="mt-4 whitespace-pre-line border-t border-gray-200 pt-4 text-sm text-gray-700">{{ $adjustment->note }}</p>
            @endif
        </x-ui.card>
    </div>
@endsection
