@extends('layouts.pos')

@section('title', 'Open drawer')

@section('content')
    <div class="mx-auto max-w-3xl p-4">
        <x-ui.page-header title="Open the cash drawer" :description="$terminal->displayName().' · count the opening float by denomination.'" />

        @if ($previous)
            <x-ui.alert type="info" class="mb-4" title="Continuing after {{ $previous->holder->name }}">
                {{ $previous->holder->name }} closed the drawer at {{ $previous->closed_at->format('H:i') }} with Rs. {{ number_format((float) $previous->counted_cash, 2) }} counted.
                Their count is filled in below; count again and correct it if needed.
            </x-ui.alert>
        @endif

        <form method="POST" action="{{ route('pos.drawer.store') }}" class="space-y-4">
            @csrf
            <x-pos.denomination-count :denominations="$denominations" :count="$count" title="Opening float" />

            <div class="flex justify-end gap-2">
                <x-ui.button variant="secondary" :href="route('dashboard')">Cancel</x-ui.button>
                <x-ui.button type="submit" size="lg">Open drawer</x-ui.button>
            </div>
        </form>
    </div>
@endsection
