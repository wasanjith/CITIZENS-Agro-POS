@extends('layouts.app')

@php $isNew = ! $category->exists; @endphp

@section('title', $isNew ? 'Add category' : 'Edit '.$category->name)

@section('content')
    <x-ui.page-header :title="$isNew ? 'Add category' : 'Edit category'" />

    <form method="POST" action="{{ $isNew ? route('catalog.categories.store') : route('catalog.categories.update', $category) }}">
        @csrf
        @unless ($isNew)
            @method('PUT')
        @endunless

        <x-ui.card>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="name" label="Name (English)" :value="$category->name" required />
                <x-ui.input name="name_si" label="Sinhala name" :value="$category->name_si" class="font-sinhala" />
                <x-ui.select name="parent_id" label="Parent category" :options="$parents" :value="$category->parent_id" placeholder="None (top level)" class="sm:col-span-2" />
                <x-ui.input name="code_from" label="Short codes from" type="number" min="1" :value="$category->code_from" hint="Optional. Sub-categories use the parent's range when empty." />
                <x-ui.input name="code_to" label="Short codes to" type="number" min="1" :value="$category->code_to" />
                <x-ui.input name="sort_order" label="Sort order" type="number" min="0" :value="$category->sort_order" />
                <x-ui.checkbox name="is_active" label="Active" :checked="$category->is_active" class="sm:col-span-2" />
            </div>

            <x-slot:footer>
                <x-ui.button variant="secondary" :href="route('catalog.categories.index')">Cancel</x-ui.button>
                <x-ui.button type="submit">{{ $isNew ? 'Create category' : 'Save changes' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
@endsection
