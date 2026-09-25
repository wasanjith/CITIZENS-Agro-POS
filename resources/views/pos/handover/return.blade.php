@extends('layouts.pos')

@section('title', 'Count and hand back')

@section('content')
    <div class="mx-auto max-w-3xl p-4">
        <x-ui.page-header
            :title="$isHolder ? 'Count and hand back the drawer' : 'Take the drawer back from '.$session->holder->name"
            :description="'Drawer of '.$session->holder->name.' since '.$session->opened_at->format('H:i').'. Count the cash; the variance is recorded on the handover slip.'"
        />

        @if ($authorityEnded && $isHolder)
            <x-ui.alert type="warning" class="mb-4" title="Your cashier authority has ended">
                Count the drawer now. You can no longer settle invoices.
            </x-ui.alert>
        @endif

        <form method="POST" action="{{ route('pos.handover.return.store') }}" class="space-y-4">
            @csrf

            <x-pos.denomination-count :denominations="$denominations" :title="$isHolder ? 'Count the drawer' : 'Count the drawer (the holder is not here)'" />

            <x-ui.card title="Who takes the drawer now">
                @if ($isHolder)
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.select name="new_holder_id" label="Owner" :options="$owners->pluck('name', 'id')->all()" placeholder="Nobody yet (owner opens later)" :value="old('new_holder_id')" />
                        <x-ui.input name="new_holder_pin" type="password" label="Owner's PIN" inputmode="numeric" maxlength="6" autocomplete="off" hint="Only if the owner takes over now." />
                    </div>
                @else
                    <input type="hidden" name="new_holder_id" value="{{ auth()->id() }}">
                    <p class="text-sm text-gray-700">You ({{ auth()->user()->name }}) take the drawer with the counted cash. {{ $session->holder->name }}'s cashier authority is revoked.</p>
                @endif
                <x-ui.input name="note" label="Note (optional)" class="mt-4" maxlength="255" />
            </x-ui.card>

            <div class="flex justify-end gap-2">
                <x-ui.button variant="secondary" :href="route('pos.drawer.show')">Cancel</x-ui.button>
                <x-ui.button type="submit" size="lg">Close the drawer and print the slip</x-ui.button>
            </div>
        </form>
    </div>
@endsection
