@extends('layouts.app')

@php $isNew = ! $tax->exists; @endphp

@section('title', $isNew ? 'Add tax' : 'Edit '.$tax->name)

@section('content')
    <x-ui.page-header :title="$isNew ? 'Add tax' : 'Edit tax'" />

    <form method="POST" action="{{ $isNew ? route('catalog.taxes.store') : route('catalog.taxes.update', $tax) }}" class="max-w-xl">
        @csrf
        @unless ($isNew)
            @method('PUT')
        @endunless

        <x-ui.card>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="name" label="Name" :value="$tax->name" required />
                <x-ui.input name="rate" label="Rate %" type="number" step="0.01" min="0" max="100" :value="$tax->rate" required />
                <x-ui.checkbox name="is_active" label="Active" :checked="$tax->is_active" class="sm:col-span-2" />
            </div>

            <x-slot:footer>
                <x-ui.button variant="secondary" :href="route('catalog.taxes.index')">Cancel</x-ui.button>
                <x-ui.button type="submit">{{ $isNew ? 'Create tax' : 'Save changes' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
@endsection
