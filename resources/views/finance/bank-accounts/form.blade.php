@extends('layouts.app')

@php $isNew = ! $bank->exists; @endphp

@section('title', $isNew ? 'Add bank account' : 'Edit '.$bank->displayName())

@section('content')
    <x-ui.page-header :title="$isNew ? 'Add bank account' : 'Edit '.$bank->displayName()" />

    <form method="POST" action="{{ $isNew ? route('finance.bank-accounts.store') : route('finance.bank-accounts.update', $bank) }}" class="max-w-3xl">
        @csrf
        @unless ($isNew)
            @method('PUT')
        @endunless

        <x-ui.card>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="bank_name" label="Bank" :value="$bank->bank_name" required placeholder="Bank of Ceylon" />
                <x-ui.input name="branch" label="Branch" :value="$bank->branch" />
                <x-ui.input name="account_no" label="Account number" :value="$bank->account_no" required />
                <x-ui.input name="account_name" label="Account name" :value="$bank->account_name" required />
                <x-ui.select name="type" label="Type" :options="$types" :value="$bank->type?->value" required />
                <div></div>
                <x-ui.money-input name="opening_balance" label="Opening balance" :value="$bank->opening_balance" hint="Balance on the statement on the start date." />
                <x-ui.date-input name="opening_date" label="Start date" :value="$bank->opening_date?->toDateString()" required />
                <x-ui.checkbox name="receives_card_payments" label="Card and bank-transfer payments go to this account" :checked="$bank->receives_card_payments" hint="Leave off when you enter card and transfer money into the bank yourself (Move money). When on, they are added to this bank book automatically." class="sm:col-span-2" />
                <x-ui.checkbox name="is_active" label="Active" :checked="$bank->is_active" class="sm:col-span-2" />
            </div>

            <x-slot:footer>
                <x-ui.button variant="secondary" :href="$isNew ? route('finance.bank-accounts.index') : route('finance.bank-accounts.show', $bank)">Cancel</x-ui.button>
                <x-ui.button type="submit">{{ $isNew ? 'Add bank account' : 'Save changes' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
@endsection
