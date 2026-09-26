@extends('layouts.app')

@section('title', 'Move money')

@section('content')
    <x-ui.page-header title="Move money" description="Enter every bank movement here: cash deposits, card and transfer money reaching the bank, withdrawals, transfers between banks, and the owner's own money in or out." />

    @foreach (['from', 'to', 'amount'] as $field)
        @error($field)<x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>@enderror
    @endforeach

    <form method="POST" action="{{ route('finance.money.store') }}" class="max-w-2xl" x-data="{ busy: false }" @submit="busy = true">
        @csrf
        <x-ui.card>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select name="from" label="From" :options="$places" :value="old('from', $from)" required />
                <x-ui.select name="to" label="To" :options="$places" :value="old('to', $to)" placeholder="Choose…" required />
                <x-ui.money-input name="amount" label="Amount (Rs.)" required />
                <x-ui.date-input name="date" label="Date" :value="old('date', today()->toDateString())" required />
                <x-ui.input name="reference" label="Reference" hint="Deposit slip or transfer number." maxlength="100" />
                <x-ui.input name="note" label="Note" maxlength="200" />
            </div>
            <p class="mt-4 text-xs text-gray-500">Cash at home = the day's takings you took home; the morning float comes from it. Owner's own money → Cash at home or a bank records money you add to the business; the other way records money you take for yourself. Cheques are deposited from the cheque page.</p>

            <x-slot:footer>
                <x-ui.button variant="secondary" :href="route('finance.bank-accounts.index')">Cancel</x-ui.button>
                <x-ui.button type="submit" x-bind:disabled="busy">Move money</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
@endsection
