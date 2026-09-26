@extends('layouts.app')

@php $isNew = ! $account->exists; @endphp

@section('title', $isNew ? 'Add account' : 'Edit '.$account->displayName())

@section('content')
    <x-ui.page-header :title="$isNew ? 'Add account' : 'Edit '.$account->displayName()" :description="$account->is_system ? 'System account: only the name and description can change.' : 'Codes: 1 assets, 2 liabilities, 3 equity, 4 income, 5 or 6 expenses.'" />

    <form method="POST" action="{{ $isNew ? route('finance.accounts.store') : route('finance.accounts.update', $account) }}" class="max-w-2xl">
        @csrf
        @unless ($isNew)
            @method('PUT')
        @endunless

        <x-ui.card>
            <div class="grid gap-4 sm:grid-cols-2">
                @unless ($account->is_system)
                    <x-ui.input name="code" label="Code" :value="$account->code" required maxlength="6" inputmode="numeric" />
                    <x-ui.select name="type" label="Type" :options="$types" :value="$account->type?->value" required />
                @endunless
                <x-ui.input name="name" label="Name" :value="$account->name" required maxlength="120" class="sm:col-span-2" />
                <x-ui.input name="description" label="Description" :value="$account->description" maxlength="255" class="sm:col-span-2" />
                @if (! $isNew && ! $account->is_system)
                    <x-ui.checkbox name="is_active" label="Active" :checked="$account->is_active" class="sm:col-span-2" />
                @endif
            </div>

            <x-slot:footer>
                <x-ui.button variant="secondary" :href="$isNew ? route('finance.accounts.index') : route('finance.accounts.show', $account)">Cancel</x-ui.button>
                <x-ui.button type="submit">{{ $isNew ? 'Add account' : 'Save changes' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
@endsection
