@extends('layouts.app')

@section('title', 'Suppliers')

@section('content')
    <x-ui.page-header title="Suppliers" description="Who the shop buys from, and what is owed to each.">
        @can('create', \App\Domain\Purchasing\Models\Supplier::class)
            <x-ui.button :href="route('purchasing.suppliers.create')">Add supplier</x-ui.button>
        @endcan
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('purchasing.suppliers.index')" placeholder="Name, contact or phone" class="mb-4">
        <x-ui.select name="filter[status]" :options="['active' => 'Active', 'inactive' => 'Inactive']" :value="request('filter.status')" placeholder="Any status" />
    </x-ui.filter-bar>

    @if ($suppliers->isEmpty())
        <x-ui.empty-state title="No suppliers found" description="Add the companies and agents the shop buys from.">
            @can('create', \App\Domain\Purchasing\Models\Supplier::class)
                <x-ui.button :href="route('purchasing.suppliers.create')">Add supplier</x-ui.button>
            @endcan
        </x-ui.empty-state>
    @else
        <x-ui.table>
            <x-slot:head>
                <x-ui.th-sortable column="name" default="name">Name</x-ui.th-sortable>
                <th>Contact</th>
                <th>Terms</th>
                <th class="text-right">Balance owed</th>
                <th>Status</th>
            </x-slot:head>

            @foreach ($suppliers as $supplier)
                @php
                    $balance = \Brick\Math\BigDecimal::of($supplier->opening_balance)->plus((string) ($supplier->credits ?? 0))->minus((string) ($supplier->debits ?? 0));
                @endphp
                <tr>
                    <td>
                        <a href="{{ route('purchasing.suppliers.show', $supplier) }}" class="font-medium text-brand-700 hover:underline">{{ $supplier->name }}</a>
                    </td>
                    <td class="text-gray-600">
                        {{ $supplier->contact_person ?: '—' }}
                        @if ($supplier->phone)
                            <p class="text-xs text-gray-500">{{ $supplier->phone }}</p>
                        @endif
                    </td>
                    <td class="text-gray-600">{{ $supplier->payment_terms_days ? $supplier->payment_terms_days.' days' : 'Cash' }}</td>
                    <td class="text-right tabular @if ($balance->isPositive()) font-semibold @endif">{{ number_format((float) (string) $balance, 2) }}</td>
                    <td><x-ui.badge :color="$supplier->is_active ? 'green' : 'red'">{{ $supplier->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.pagination :paginator="$suppliers" />
    @endif
@endsection
