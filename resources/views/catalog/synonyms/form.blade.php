@extends('layouts.app')

@php $isNew = ! $synonym->exists; @endphp

@section('title', $isNew ? 'Add synonyms' : 'Edit synonyms')

@section('content')
    <x-ui.page-header :title="$isNew ? 'Add synonyms' : 'Edit synonyms for “'.$synonym->term.'”'" />

    <form method="POST" action="{{ $isNew ? route('catalog.synonyms.store') : route('catalog.synonyms.update', $synonym) }}" class="max-w-xl">
        @csrf
        @unless ($isNew)
            @method('PUT')
        @endunless

        <x-ui.card>
            <div class="space-y-4">
                <x-ui.input name="term" label="Word" :value="$synonym->term" hint="e.g. tsp" required />
                <x-ui.textarea
                    name="synonyms"
                    label="Means the same as"
                    :value="implode(', ', $synonym->synonyms ?? [])"
                    hint="Separate with commas, e.g. triple super phosphate, t.s.p"
                    required
                />
                <x-ui.field-error name="synonym_list" />
            </div>

            <x-slot:footer>
                <x-ui.button variant="secondary" :href="route('catalog.synonyms.index')">Cancel</x-ui.button>
                <x-ui.button type="submit">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
@endsection
