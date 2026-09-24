@extends('layouts.app')

@section('title', 'Search synonyms')

@section('content')
    <x-ui.page-header title="Search synonyms" description="Words that mean the same thing in search, both ways: searching either one finds the other.">
        <x-ui.button :href="route('catalog.synonyms.create')">Add synonyms</x-ui.button>
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('catalog.synonyms.index')" placeholder="Search words" class="mb-4" />

    @if ($synonyms->isEmpty())
        <x-ui.empty-state title="No synonyms yet" description="Example: tsp = triple super phosphate." />
    @else
        <x-ui.table>
            <x-slot:head>
                <x-ui.th-sortable column="term" default="term">Word</x-ui.th-sortable>
                <th>Means the same as</th>
                <th><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($synonyms as $synonym)
                <tr>
                    <td class="font-medium">{{ $synonym->term }}</td>
                    <td>
                        <div class="flex flex-wrap gap-1">
                            @foreach ($synonym->synonyms as $word)
                                <x-ui.badge>{{ $word }}</x-ui.badge>
                            @endforeach
                        </div>
                    </td>
                    <td class="whitespace-nowrap text-right [&>a]:ml-4 [&>button]:ml-4">
                        <x-ui.button variant="link" :href="route('catalog.synonyms.edit', $synonym)">Edit</x-ui.button>
                        <x-ui.button variant="link" class="text-red-700" x-data x-on:click="$dispatch('open-modal', 'delete-synonym-{{ $synonym->id }}')">Delete</x-ui.button>
                        <x-ui.confirm-modal
                            name="delete-synonym-{{ $synonym->id }}"
                            :action="route('catalog.synonyms.destroy', $synonym)"
                            method="DELETE"
                            title="Delete synonyms for &quot;{{ $synonym->term }}&quot;?"
                            confirm="Delete"
                        >
                            Search stops treating these words as the same.
                        </x-ui.confirm-modal>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.pagination :paginator="$synonyms" />
    @endif
@endsection
