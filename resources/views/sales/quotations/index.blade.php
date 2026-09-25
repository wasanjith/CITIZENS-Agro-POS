@extends('layouts.app')

@section('title', 'Quotations')

@section('content')
    <x-ui.page-header title="Quotations" description="Price offers printed at the counters (F7). Load one on a counter to bill it." />

    <x-ui.filter-bar :action="route('quotations.index')" placeholder="Number or customer name" class="mb-4">
        <x-ui.select name="filter[status]" :options="$statuses" :value="request('filter.status')" placeholder="Any status" />
    </x-ui.filter-bar>

    @if ($quotations->isEmpty())
        <x-ui.empty-state title="No quotations found" description="Build a bill on the counter screen and press F7 to print a quotation." />
    @else
        <x-ui.table>
            <x-slot:head>
                <x-ui.th-sortable column="number">Quotation</x-ui.th-sortable>
                <x-ui.th-sortable column="created_at" default="created_at">Date</x-ui.th-sortable>
                <th>Customer</th>
                <th>By</th>
                <x-ui.th-sortable column="valid_until">Valid until</x-ui.th-sortable>
                <th>Status</th>
                <x-ui.th-sortable column="total">Total</x-ui.th-sortable>
            </x-slot:head>
            @foreach ($quotations as $quotation)
                @php $status = $quotation->effectiveStatus(); @endphp
                <tr>
                    <td><a href="{{ route('quotations.show', $quotation) }}" class="font-mono text-brand-700 hover:underline">{{ $quotation->number }}</a></td>
                    <td class="text-gray-600">{{ $quotation->created_at->format('Y-m-d H:i') }}</td>
                    <td>{{ $quotation->customerLabel() ?? '—' }}</td>
                    <td class="text-gray-600">{{ $quotation->creator->name }}</td>
                    <td class="text-gray-600">{{ $quotation->valid_until->format('Y-m-d') }}</td>
                    <td>
                        <x-ui.badge :color="$status->color()">{{ $status->label() }}</x-ui.badge>
                        @if ($quotation->convertedSale)<span class="text-xs text-gray-500">{{ $quotation->convertedSale->invoice_no }}</span>@endif
                    </td>
                    <td class="text-right tabular">{{ number_format((float) $quotation->total, 2) }}</td>
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$quotations" />
    @endif
@endsection
