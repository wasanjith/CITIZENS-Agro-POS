@extends('layouts.app')

@php $isNew = ! $supplier->exists; @endphp

@section('title', $isNew ? 'Add supplier' : 'Edit '.$supplier->name)

@section('content')
    <x-ui.page-header :title="$isNew ? 'Add supplier' : 'Edit '.$supplier->name" />

    <form method="POST" action="{{ $isNew ? route('purchasing.suppliers.store') : route('purchasing.suppliers.update', $supplier) }}" class="max-w-3xl">
        @csrf
        @unless ($isNew)
            @method('PUT')
        @endunless

        <x-ui.card>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="name" label="Name" :value="$supplier->name" required class="sm:col-span-2" />
                <x-ui.input name="contact_person" label="Contact person" :value="$supplier->contact_person" />
                <x-ui.input name="phone" label="Phone" :value="$supplier->phone" hint="Used for sending purchase orders by WhatsApp." />
                <x-ui.input name="email" label="E-mail" type="email" :value="$supplier->email" />
                <x-ui.input name="payment_terms_days" label="Payment terms (days)" type="number" min="0" max="365" :value="$supplier->payment_terms_days" hint="0 = cash on delivery." />
                <x-ui.textarea name="address" label="Address" :value="$supplier->address" rows="2" class="sm:col-span-2" />
                <x-ui.money-input name="opening_balance" label="Opening balance owed" :value="$supplier->opening_balance" hint="What the shop owed this supplier when the system went live." />
                <x-ui.checkbox name="is_active" label="Active" :checked="$supplier->is_active" class="sm:col-span-2" />
            </div>

            <x-slot:footer>
                <x-ui.button variant="secondary" :href="$isNew ? route('purchasing.suppliers.index') : route('purchasing.suppliers.show', $supplier)">Cancel</x-ui.button>
                <x-ui.button type="submit">{{ $isNew ? 'Create supplier' : 'Save changes' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
@endsection
