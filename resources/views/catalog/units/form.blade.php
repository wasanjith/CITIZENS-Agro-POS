@extends('layouts.app')

@php $isNew = ! $unit->exists; @endphp

@section('title', $isNew ? 'Add unit' : 'Edit '.$unit->name)

@section('content')
    <x-ui.page-header :title="$isNew ? 'Add unit' : 'Edit unit'" />

    <form method="POST" action="{{ $isNew ? route('catalog.units.store') : route('catalog.units.update', $unit) }}" class="max-w-xl">
        @csrf
        @unless ($isNew)
            @method('PUT')
        @endunless

        <x-ui.card>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="name" label="Name" :value="$unit->name" hint="Lower case, e.g. bag" required />
                <x-ui.input name="symbol" label="Symbol" :value="$unit->symbol" hint="Shown on invoices, e.g. kg" required />
                <x-ui.input name="name_si" label="Sinhala name" :value="$unit->name_si" class="font-sinhala sm:col-span-2" />
                <x-ui.checkbox name="allows_decimal" label="Allow decimal quantities" hint="Tick for kg, litre, metre. Leave empty for bag, piece, packet." :checked="$unit->allows_decimal" class="sm:col-span-2" />
            </div>

            <x-slot:footer>
                <x-ui.button variant="secondary" :href="route('catalog.units.index')">Cancel</x-ui.button>
                <x-ui.button type="submit">{{ $isNew ? 'Create unit' : 'Save changes' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
@endsection
