@extends('layouts.app')

@php $isNew = ! $customer->exists; @endphp

@section('title', $isNew ? 'Add customer' : 'Edit '.$customer->name)

@section('content')
    <x-ui.page-header :title="$isNew ? 'Add customer' : 'Edit '.$customer->name" :description="$isNew ? 'The customer number is given when you save.' : $customer->code" />

    <form method="POST" action="{{ $isNew ? route('customers.store') : route('customers.update', $customer) }}" class="max-w-3xl">
        @csrf
        @unless ($isNew)
            @method('PUT')
        @endunless

        <x-ui.card>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="name" label="Name" :value="$customer->name" required />
                <x-ui.input name="name_si" label="Name in Sinhala" :value="$customer->name_si" class="font-sinhala" hint="Printed on Sinhala invoices and statements." />
                <x-ui.input name="phone" label="Phone" :value="$customer->phone" inputmode="tel" hint="Counter staff find customers by phone." />
                <x-ui.input name="nic" label="NIC" :value="$customer->nic" hint="881234567V or 198812345678. Optional." />
                <x-ui.input name="area" label="Village / area" :value="$customer->area" />
                <x-ui.select name="price_list_id" label="Price list" :options="$priceLists" :value="$customer->price_list_id" placeholder="Default (Retail)" hint="Chosen automatically on the bill when this customer is selected." />
                <x-ui.textarea name="address" label="Address" :value="$customer->address" rows="2" class="sm:col-span-2" />
            </div>
        </x-ui.card>

        <x-ui.card title="Credit" description="Credit sales above the limit need the owner at settlement." class="mt-6">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.money-input name="credit_limit" label="Credit limit (Rs.)" :value="$customer->credit_limit" hint="0 = no credit without the owner's approval." />
                <x-ui.input name="credit_days" label="Credit days" type="number" min="0" max="365" :value="$customer->credit_days" hint="A credit invoice is overdue after this many days." />
                @if ($isNew)
                    <x-ui.money-input name="opening_balance" label="Opening balance owed (Rs.)" :value="0" hint="What the customer owed in the old books when the system went live." />
                @endif
                <x-ui.textarea name="notes" label="Notes" :value="$customer->notes" rows="2" class="sm:col-span-2" />
                <x-ui.checkbox name="is_active" label="Active" :checked="$customer->is_active" class="sm:col-span-2" />
            </div>

            <x-slot:footer>
                <x-ui.button variant="secondary" :href="$isNew ? route('customers.index') : route('customers.show', $customer)">Cancel</x-ui.button>
                <x-ui.button type="submit">{{ $isNew ? 'Create customer' : 'Save changes' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
@endsection
