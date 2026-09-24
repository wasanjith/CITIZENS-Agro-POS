@extends('layouts.guest')

@section('title', 'Sign in')

@section('content')
    @php $terminal = app(\App\Domain\Identity\Support\CurrentTerminal::class)->get(); @endphp

    <h1 class="text-lg font-semibold text-gray-900">Sign in</h1>

    @if ($terminal)
        <x-ui.alert type="info" class="mt-4">
            This device is <strong>{{ $terminal->displayName() }}</strong>.
            <a href="{{ route('pin-login') }}" class="font-semibold underline">Sign in with your PIN</a>
        </x-ui.alert>
    @endif

    <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
        @csrf
        <x-ui.input name="username" label="Username" autocomplete="username" autofocus required />
        <x-ui.input name="password" label="Password" type="password" autocomplete="current-password" required />
        <x-ui.checkbox name="remember" label="Keep me signed in" />
        <x-ui.button type="submit" class="w-full" size="lg">Sign in</x-ui.button>
    </form>
@endsection
