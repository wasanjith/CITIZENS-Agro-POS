@extends('layouts.pos')

@section('title', 'Cashier authority ended')

@section('content')
    <div class="mx-auto max-w-xl p-6">
        <div class="rounded-lg bg-white p-6 text-center shadow-sm ring-1 ring-red-200">
            <p class="text-4xl" aria-hidden="true">⛔</p>
            <h1 class="mt-2 text-xl font-semibold text-red-800">Cashier authority revoked, count the drawer</h1>
            @php $delegation = $session->delegation; @endphp
            <p class="mt-2 text-sm text-gray-600">
                @if ($delegation?->revoked_at)
                    {{ $delegation->revokedBy?->name ?? 'The owner' }} revoked your cashier authority at {{ $delegation->revoked_at->format('H:i') }}.
                @elseif ($delegation)
                    Your cashier authority ended at {{ $delegation->expires_at->format('H:i') }}.
                @else
                    You no longer hold cashier authority.
                @endif
                You cannot settle invoices. Count the drawer and hand it back.
            </p>
            <x-ui.button class="mt-6" size="lg" :href="route('pos.handover.return')">Count and hand back</x-ui.button>
        </div>
    </div>
@endsection
