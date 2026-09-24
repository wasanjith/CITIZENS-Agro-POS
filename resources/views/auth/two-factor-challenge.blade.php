@extends('layouts.guest')

@section('title', 'Two-factor authentication')

@section('content')
    <div x-data="{ recovery: false }">
        <h1 class="text-lg font-semibold text-gray-900">Two-factor authentication</h1>
        <p class="mt-1 text-sm text-gray-500" x-show="! recovery">Enter the 6-digit code from your authenticator app.</p>
        <p class="mt-1 text-sm text-gray-500" x-show="recovery" x-cloak>Enter one of your emergency recovery codes.</p>

        <form method="POST" action="{{ route('two-factor.login.store') }}" class="mt-6 space-y-4">
            @csrf
            <div x-show="! recovery">
                <x-ui.input name="code" label="Code" inputmode="numeric" autocomplete="one-time-code" autofocus x-bind:disabled="recovery" />
            </div>
            <div x-show="recovery" x-cloak>
                <x-ui.input name="recovery_code" label="Recovery code" autocomplete="off" x-bind:disabled="! recovery" />
            </div>
            <x-ui.button type="submit" class="w-full" size="lg">Continue</x-ui.button>
        </form>

        <button type="button" class="mt-4 text-sm text-brand-700 underline" @click="recovery = ! recovery">
            <span x-show="! recovery">Use a recovery code</span>
            <span x-show="recovery" x-cloak>Use an authenticator code</span>
        </button>
    </div>
@endsection
