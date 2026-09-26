@extends('layouts.app')

@section('title', 'Record expense')

@section('content')
    <x-ui.page-header title="Record expense" description="Take a photo of the bill so it can be found later." />

    @foreach (['paid_from', 'amount', 'bank_account_id', 'expense_category_id', 'receipt'] as $field)
        @error($field)<x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>@enderror
    @endforeach

    @if (array_key_exists('cash_drawer', $sources) && ! $drawerOpen)
        <x-ui.alert type="warning" class="mb-4">No drawer is open at the main cashier, so petty cash cannot be paid out right now.</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('finance.expenses.store') }}" enctype="multipart/form-data" class="max-w-3xl"
          x-data="{ from: @js(old('paid_from', array_key_first($sources))), busy: false }" @submit="busy = true">
        @csrf
        <x-ui.card>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select name="expense_category_id" label="Spent on" :options="$categories" placeholder="Choose…" required />
                <x-ui.money-input name="amount" label="Amount (Rs.)" required />
                <x-ui.select name="paid_from" label="Paid from" :options="$sources" required x-model="from" />
                <div x-show="from === 'bank'" x-cloak>
                    <x-ui.select name="bank_account_id" label="Bank account" :options="$banks" placeholder="Choose…" />
                </div>
                <x-ui.date-input name="date" label="Date" :value="old('date', today()->toDateString())" required hint="Petty cash from the drawer is always today." />
                <x-ui.input name="payee" label="Paid to" maxlength="150" placeholder="CEB, lorry driver …" />
                <x-ui.input name="reference" label="Bill / reference no." maxlength="100" />
                <x-ui.input name="note" label="Note" maxlength="255" class="sm:col-span-2" />
                <div class="sm:col-span-2">
                    <label for="receipt" class="block text-sm font-medium text-gray-700">Photo of the bill</label>
                    <input id="receipt" name="receipt" type="file" accept="image/*,application/pdf" capture="environment" class="mt-1 block w-full text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-brand-700">
                    <p class="mt-1 text-xs text-gray-500">JPG, PNG, WebP or PDF, up to 5 MB.</p>
                </div>
            </div>

            <x-slot:footer>
                <x-ui.button variant="secondary" :href="route('finance.expenses.index')">Cancel</x-ui.button>
                <x-ui.button type="submit" x-bind:disabled="busy">Record expense</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
@endsection
