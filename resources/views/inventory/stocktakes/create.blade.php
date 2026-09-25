@extends('layouts.app')

@section('title', 'Start a stocktake')

@section('content')
    <x-ui.page-header title="Start a stocktake" description="The system quantities are frozen when you start. Count soon after, ideally while nothing is being sold from these shelves." />

    <form method="POST" action="{{ route('inventory.stocktakes.store') }}" class="max-w-3xl">
        @csrf

        <x-ui.card>
            @error('categories')
                <x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>
            @enderror

            <fieldset>
                <legend class="text-sm font-medium text-gray-700">What to count</legend>
                <p class="text-xs text-gray-500">Tick categories (sub-categories are included), or leave all unticked to count the whole shop.</p>
                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                    @foreach ($categories as $id => $label)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="categories[]" value="{{ $id }}" @checked(in_array((string) $id, (array) old('categories', []), true)) class="size-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <x-ui.textarea name="note" label="Note" rows="2" class="mt-4" />

            <x-slot:footer>
                <x-ui.button variant="secondary" :href="route('inventory.stocktakes.index')">Cancel</x-ui.button>
                <x-ui.button type="submit">Start counting</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
@endsection
