@extends('layouts.app')

@section('title', 'Brands')

@section('content')
    <x-ui.page-header title="Brands">
        <x-ui.button :href="route('catalog.brands.create')">Add brand</x-ui.button>
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('catalog.brands.index')" placeholder="Search brands" class="mb-4" />

    @if ($brands->isEmpty())
        <x-ui.empty-state title="No brands found" />
    @else
        <x-ui.table>
            <x-slot:head>
                <x-ui.th-sortable column="name" default="name">Brand</x-ui.th-sortable>
                <x-ui.th-sortable column="products_count" default="name" class="text-right">Products</x-ui.th-sortable>
                <th>Status</th>
                <th><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($brands as $brand)
                <tr>
                    <td class="font-medium">{{ $brand->name }}</td>
                    <td class="text-right tabular">
                        <a href="{{ route('catalog.products.index', ['filter' => ['brand' => $brand->id]]) }}" class="text-brand-700 hover:underline">{{ $brand->products_count }}</a>
                    </td>
                    <td><x-ui.badge :color="$brand->is_active ? 'green' : 'red'">{{ $brand->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
                    <td class="whitespace-nowrap text-right [&>a]:ml-4 [&>button]:ml-4">
                        <x-ui.button variant="link" :href="route('catalog.brands.edit', $brand)">Edit</x-ui.button>
                        @if ($brand->products_count === 0)
                            <x-ui.button variant="link" class="text-red-700" x-data x-on:click="$dispatch('open-modal', 'delete-brand-{{ $brand->id }}')">Delete</x-ui.button>
                            <x-ui.confirm-modal
                                name="delete-brand-{{ $brand->id }}"
                                :action="route('catalog.brands.destroy', $brand)"
                                method="DELETE"
                                title="Delete {{ $brand->name }}?"
                                confirm="Delete"
                            >
                                The brand is removed from the list.
                            </x-ui.confirm-modal>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.pagination :paginator="$brands" />
    @endif
@endsection
