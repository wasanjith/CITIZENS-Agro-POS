@extends('layouts.app')

@section('title', 'Categories')

@section('content')
    <x-ui.page-header title="Categories" description="The category tree. A code range (e.g. 1000–1999) gives new products in the category their next free short code.">
        <x-ui.button :href="route('catalog.categories.create')">Add category</x-ui.button>
    </x-ui.page-header>

    @if ($categories === [])
        <x-ui.empty-state title="No categories yet" />
    @else
        <x-ui.table>
            <x-slot:head>
                <th>Category</th>
                <th>Sinhala</th>
                <th>Code range</th>
                <th class="text-right">Products</th>
                <th>Status</th>
                <th><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($categories as $category)
                <tr>
                    <td>
                        <span style="padding-left: {{ $category->depth * 1.5 }}rem" @class(['font-semibold' => $category->depth === 0])>
                            @if ($category->depth > 0)<span class="text-gray-400">└</span>@endif
                            {{ $category->name }}
                        </span>
                    </td>
                    <td class="font-sinhala text-gray-600">{{ $category->name_si }}</td>
                    <td class="tabular text-gray-600">
                        @if ($category->code_from !== null)
                            {{ $category->code_from }}–{{ $category->code_to }}
                        @elseif ($category->depth > 0)
                            <span class="text-xs text-gray-400">from parent</span>
                        @else
                            —
                        @endif
                    </td>
                    <td class="text-right tabular">
                        <a href="{{ route('catalog.products.index', ['filter' => ['category' => $category->id]]) }}" class="text-brand-700 hover:underline">{{ $category->products_count }}</a>
                    </td>
                    <td>
                        <x-ui.badge :color="$category->is_active ? 'green' : 'red'">{{ $category->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
                    </td>
                    <td class="whitespace-nowrap text-right [&>a]:ml-4 [&>button]:ml-4">
                        <x-ui.button variant="link" :href="route('catalog.categories.create', ['parent_id' => $category->id])">Add sub-category</x-ui.button>
                        <x-ui.button variant="link" :href="route('catalog.categories.edit', $category)">Edit</x-ui.button>
                        @if ($category->products_count === 0)
                            <x-ui.button variant="link" class="text-red-700" x-data x-on:click="$dispatch('open-modal', 'delete-category-{{ $category->id }}')">Delete</x-ui.button>
                            <x-ui.confirm-modal
                                name="delete-category-{{ $category->id }}"
                                :action="route('catalog.categories.destroy', $category)"
                                method="DELETE"
                                title="Delete {{ $category->name }}?"
                                confirm="Delete"
                            >
                                The category is removed from the tree.
                            </x-ui.confirm-modal>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    @endif
@endsection
