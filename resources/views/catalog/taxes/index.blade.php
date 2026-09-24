@extends('layouts.app')

@section('title', 'Taxes')

@section('content')
    <x-ui.page-header title="Taxes" description="Tax rates that can be set on products. Products without a tax are sold tax-free.">
        <x-ui.button :href="route('catalog.taxes.create')">Add tax</x-ui.button>
    </x-ui.page-header>

    <x-ui.table>
        <x-slot:head>
            <th>Tax</th>
            <th class="text-right">Rate</th>
            <th class="text-right">Products</th>
            <th>Status</th>
            <th><span class="sr-only">Actions</span></th>
        </x-slot:head>

        @forelse ($taxes as $tax)
            <tr>
                <td class="font-medium">{{ $tax->name }}</td>
                <td class="text-right tabular">{{ $tax->rate }}%</td>
                <td class="text-right tabular">{{ $tax->products_count }}</td>
                <td><x-ui.badge :color="$tax->is_active ? 'green' : 'gray'">{{ $tax->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
                <td class="text-right">
                    <x-ui.button variant="link" :href="route('catalog.taxes.edit', $tax)">Edit</x-ui.button>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-center text-gray-500">No taxes yet.</td></tr>
        @endforelse
    </x-ui.table>
@endsection
