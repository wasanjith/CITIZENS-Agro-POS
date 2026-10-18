@extends('layouts.app')

@php $isNew = ! $brand->exists; @endphp

@section('title', $isNew ? 'Add brand' : 'Edit '.$brand->name)

@section('content')
    <x-ui.page-header :title="$isNew ? 'Add brand' : 'Edit brand'" />

    <form method="POST" action="{{ $isNew ? route('catalog.brands.store') : route('catalog.brands.update', $brand) }}" class="max-w-xl">
        @csrf
        @unless ($isNew)
            @method('PUT')
        @endunless

        <x-ui.card>
            <div class="space-y-4">
                <x-ui.input name="name" label="Name" :value="$brand->name" required />
                <x-ui.select name="supplier_id" label="Supplier" :options="$suppliers" :value="$brand->supplier_id" placeholder="Choose…" required hint="A brand is bought from one supplier. Its products are suggested on that supplier's purchase orders." />
                <x-ui.checkbox name="is_active" label="Active" :checked="$brand->is_active" />
            </div>

            <x-slot:footer>
                <x-ui.button variant="secondary" :href="route('catalog.brands.index')">Cancel</x-ui.button>
                <x-ui.button type="submit">{{ $isNew ? 'Create brand' : 'Save changes' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
@endsection
