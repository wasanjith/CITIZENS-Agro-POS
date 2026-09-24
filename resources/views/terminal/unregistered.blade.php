@extends('layouts.guest')

@section('title', 'Device not registered')

@section('content')
    <h1 class="text-lg font-semibold text-gray-900">This device is not a registered terminal</h1>
    <p class="mt-2 text-sm text-gray-600">
        PIN sign-in and the POS screens only work on the shop's registered terminals
        (the main cashier PC and Counters 1–3).
    </p>
    <p class="mt-2 text-sm text-gray-600">
        To register this PC, a Super Admin signs in here with a username and password, opens
        <strong>Terminals</strong> and chooses <strong>Register this device</strong>.
    </p>
    <x-ui.button :href="route('login')" class="mt-6 w-full" size="lg">Sign in with username and password</x-ui.button>
@endsection
