@extends('layouts.app')

@section('title', 'Register '.$terminal->name)

@section('content')
    <x-ui.page-header :title="'Register this device as '.$terminal->name" />

    <x-ui.card class="max-w-2xl">
        <div class="space-y-3 text-sm text-gray-600">
            <p>Only do this on the PC that will be <strong>{{ $terminal->displayName() }}</strong>.</p>
            <ul class="list-inside list-disc space-y-1">
                <li>This browser will be remembered as {{ $terminal->name }}, and staff can sign in here with their PIN.</li>
                @if ($terminal->isRegistered())
                    <li class="text-amber-800">{{ $terminal->name }} is already registered on another device (since {{ $terminal->registered_at?->format('Y-m-d H:i') }}). That device will stop working as {{ $terminal->name }}.</li>
                @endif
                @if ($currentTerminal)
                    <li class="text-amber-800">This browser is currently registered as {{ $currentTerminal->name }}. It will be switched to {{ $terminal->name }}.</li>
                @endif
                <li>Clearing the browser's cookies removes the registration; register again if that happens.</li>
            </ul>
        </div>

        <x-slot:footer>
            <x-ui.button variant="secondary" :href="route('admin.terminals.index')">Cancel</x-ui.button>
            <form method="POST" action="{{ route('admin.terminals.register.store', $terminal) }}">
                @csrf
                <x-ui.button type="submit">Register this device</x-ui.button>
            </form>
        </x-slot:footer>
    </x-ui.card>
@endsection
