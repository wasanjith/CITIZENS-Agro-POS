@extends('layouts.app')

@section('title', 'My account')

@section('content')
    <x-ui.page-header title="My account" :description="$user->username.' · '.$user->primaryRole()?->label()" />

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card title="Change password">
            @if (session('status') === 'password-updated')
                <x-ui.alert type="success" class="mb-4">Password changed.</x-ui.alert>
            @endif

            <form method="POST" action="{{ route('user-password.update') }}" class="space-y-4" id="password-form">
                @csrf
                @method('PUT')
                <x-ui.input name="current_password" label="Current password" type="password" autocomplete="current-password" bag="updatePassword" required />
                <x-ui.input name="password" label="New password" type="password" autocomplete="new-password" bag="updatePassword" required />
                <x-ui.input name="password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" bag="updatePassword" required />
            </form>

            <x-slot:footer>
                <x-ui.button type="submit" form="password-form">Change password</x-ui.button>
            </x-slot:footer>
        </x-ui.card>

        <x-ui.card title="Two-factor authentication" description="A code from an authenticator app (e.g. Google Authenticator) is asked after the password.">
            @if (! $user->two_factor_secret)
                <p class="text-sm text-gray-600">Two-factor authentication is <strong>off</strong>.</p>
                <form method="POST" action="{{ route('two-factor.enable') }}" class="mt-4">
                    @csrf
                    <x-ui.button type="submit">Turn on</x-ui.button>
                </form>
            @elseif (! $user->two_factor_confirmed_at)
                <p class="text-sm text-gray-600">Scan this QR code with your authenticator app, then enter the 6-digit code to finish.</p>
                <div class="mt-4 inline-block rounded-lg bg-white p-3 ring-1 ring-gray-200">{!! $user->twoFactorQrCodeSvg() !!}</div>

                <form method="POST" action="{{ route('two-factor.confirm') }}" class="mt-4 flex items-end gap-3">
                    @csrf
                    <x-ui.input name="code" label="Code" inputmode="numeric" autocomplete="one-time-code" bag="confirmTwoFactorAuthentication" class="w-40" />
                    <x-ui.button type="submit">Confirm</x-ui.button>
                </form>
            @else
                <p class="text-sm text-gray-600">Two-factor authentication is <strong class="text-brand-700">on</strong>.</p>

                <details class="mt-4 text-sm">
                    <summary class="cursor-pointer font-medium text-gray-700">Show recovery codes</summary>
                    <p class="mt-2 text-gray-500">Keep these somewhere safe. Each one can be used once if you lose your phone.</p>
                    <ul class="mt-2 grid grid-cols-2 gap-1 rounded bg-gray-50 p-3 font-mono text-xs">
                        @foreach ($user->recoveryCodes() as $code)
                            <li>{{ $code }}</li>
                        @endforeach
                    </ul>
                </details>

                <form method="POST" action="{{ route('two-factor.disable') }}" class="mt-4">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="danger">Turn off</x-ui.button>
                </form>
            @endif
        </x-ui.card>
    </div>
@endsection
