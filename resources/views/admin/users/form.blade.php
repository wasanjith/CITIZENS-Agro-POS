@extends('layouts.app')

@php
    $isNew = ! $user->exists;
    $pinRules = config('pos.pin');
@endphp

@section('title', $isNew ? 'Add user' : 'Edit '.$user->name)

@section('content')
    <x-ui.page-header :title="$isNew ? 'Add user' : 'Edit user'" :description="$isNew ? null : $user->username" />

    <form method="POST" action="{{ $isNew ? route('admin.users.store') : route('admin.users.update', $user) }}" class="space-y-6">
        @csrf
        @unless ($isNew)
            @method('PUT')
        @endunless

        <x-ui.card title="Details">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="name" label="Full name" :value="$user->name" required />
                <x-ui.input name="username" label="Username" :value="$user->username" hint="Letters, numbers, dashes. Used to sign in." required />
                <x-ui.input name="email" label="E-mail (optional)" type="email" :value="$user->email" />
                <x-ui.select
                    name="role"
                    label="Role"
                    :options="collect($roles)->mapWithKeys(fn ($role) => [$role->value => $role->label()])->all()"
                    :value="$user->primaryRole()?->value ?? \App\Domain\Identity\Enums\Role::SalesStaff->value"
                    required
                />
                <x-ui.checkbox name="is_active" label="Active" :checked="$user->is_active" hint="Inactive users cannot sign in." class="sm:col-span-2" />
            </div>
        </x-ui.card>

        <x-ui.card title="Password" :description="$isNew ? 'Used for username and password sign-in.' : 'Leave empty to keep the current password.'">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="password" label="{{ $isNew ? 'Password' : 'New password' }}" type="password" autocomplete="new-password" :required="$isNew" />
                <x-ui.input name="password_confirmation" label="Confirm password" type="password" autocomplete="new-password" :required="$isNew" />
            </div>
        </x-ui.card>

        <x-ui.card title="PIN" description="{{ $pinRules['min_length'] }}–{{ $pinRules['max_length'] }} digits for quick sign-in on the shop terminals. {{ $user->hasPin() ? 'A PIN is set; leave empty to keep it.' : 'No PIN set yet.' }}">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="pin" label="New PIN" type="password" inputmode="numeric" autocomplete="off" maxlength="{{ $pinRules['max_length'] }}" />
                <x-ui.input name="pin_confirmation" label="Confirm PIN" type="password" inputmode="numeric" autocomplete="off" maxlength="{{ $pinRules['max_length'] }}" />
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="secondary" :href="route('admin.users.index')">Cancel</x-ui.button>
            <x-ui.button type="submit">{{ $isNew ? 'Create user' : 'Save changes' }}</x-ui.button>
        </div>
    </form>
@endsection
