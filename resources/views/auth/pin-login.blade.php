@extends('layouts.guest')

@section('title', 'PIN sign in')
@section('width', 'max-w-2xl')

@section('content')
    <div
        x-data="{
            userId: @js(old('user_id')),
            userName: '',
            pin: '',
            maxLength: @js(config('pos.pin.max_length')),
            select(id, name) { this.userId = id; this.userName = name; this.pin = ''; this.$nextTick(() => this.$refs.pin.focus()); },
            press(digit) { if (this.pin.length < this.maxLength) { this.pin += digit; } this.$refs.pin.focus(); },
            back() { this.pin = this.pin.slice(0, -1); this.$refs.pin.focus(); },
        }"
    >
        <div class="flex items-center justify-between">
            <h1 class="text-lg font-semibold text-gray-900">Who is signing in?</h1>
            <x-ui.badge :color="$terminal->isMainCashier() ? 'purple' : 'blue'">{{ $terminal->displayName() }}</x-ui.badge>
        </div>

        @if ($users->isEmpty())
            <x-ui.empty-state class="mt-6" title="No users have a PIN yet" description="A Super Admin can set PINs on the Users page." />
        @else
            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ($users as $pinUser)
                    <button
                        type="button"
                        @click="select({{ $pinUser->id }}, @js($pinUser->name))"
                        :class="userId === {{ $pinUser->id }} ? 'ring-2 ring-brand-600 bg-brand-50' : 'ring-1 ring-gray-200 hover:bg-gray-50'"
                        class="flex flex-col items-center gap-2 rounded-lg p-3 text-center"
                    >
                        <span class="flex size-12 items-center justify-center rounded-full bg-brand-600 text-sm font-semibold text-white">{{ $pinUser->initials() }}</span>
                        <span class="text-sm font-medium leading-tight">{{ $pinUser->name }}</span>
                        <span class="text-xs text-gray-500">{{ $pinUser->primaryRole()?->label() }}</span>
                    </button>
                @endforeach
            </div>

            <form method="POST" action="{{ route('pin-login.store') }}" class="mt-6" x-show="userId" x-cloak>
                @csrf
                <input type="hidden" name="user_id" :value="userId">

                <label for="pin" class="block text-sm font-medium text-gray-700">PIN <span x-text="userName ? 'for ' + userName : ''"></span></label>
                <input
                    type="password"
                    name="pin"
                    id="pin"
                    x-ref="pin"
                    x-model="pin"
                    inputmode="numeric"
                    autocomplete="off"
                    :maxlength="maxLength"
                    class="mt-1 block w-full rounded-md border-gray-300 text-center text-2xl tracking-[0.5em] shadow-sm focus:border-brand-500 focus:ring-brand-500"
                >
                <x-ui.field-error name="pin" />
                <x-ui.field-error name="user_id" />

                <div class="mx-auto mt-4 grid max-w-xs grid-cols-3 gap-2">
                    @foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $digit)
                        <button type="button" @click="press('{{ $digit }}')" class="rounded-lg bg-gray-100 py-3 text-xl font-semibold hover:bg-gray-200">{{ $digit }}</button>
                    @endforeach
                    <button type="button" @click="back()" class="rounded-lg bg-gray-100 py-3 text-sm font-semibold hover:bg-gray-200">⌫</button>
                    <button type="button" @click="press('0')" class="rounded-lg bg-gray-100 py-3 text-xl font-semibold hover:bg-gray-200">0</button>
                    <button type="submit" class="rounded-lg bg-brand-600 py-3 text-sm font-semibold text-white hover:bg-brand-700">OK</button>
                </div>
            </form>
        @endif
    </div>
@endsection

@section('below')
    <a href="{{ route('login') }}" class="underline">Sign in with username and password</a>
@endsection
