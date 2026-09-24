@extends('layouts.guest')

@section('title', 'Confirm password')

@section('content')
    <h1 class="text-lg font-semibold text-gray-900">Confirm your password</h1>
    <p class="mt-1 text-sm text-gray-500">This is a secure area. Please confirm your password to continue.</p>

    <form method="POST" action="{{ route('password.confirm.store') }}" class="mt-6 space-y-4">
        @csrf
        <x-ui.input name="password" label="Password" type="password" autocomplete="current-password" autofocus required />
        <x-ui.button type="submit" class="w-full" size="lg">Confirm</x-ui.button>
    </form>
@endsection
