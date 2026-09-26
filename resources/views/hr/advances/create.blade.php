@extends('layouts.app')

@section('title', 'Give salary advance')

@section('content')
    <x-ui.page-header title="Give salary advance" description="The money is recovered from the next payslips in equal installments." />

    @foreach (['paid_from', 'amount', 'bank_account_id', 'employee_id'] as $field)
        @error($field)<x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>@enderror
    @endforeach

    @if (! $drawerOpen)
        <x-ui.alert type="warning" class="mb-4">No drawer is open at the main cashier, so the advance cannot be paid from the drawer right now.</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('hr.advances.store') }}" class="max-w-3xl"
          x-data="{ from: @js(old('paid_from', 'safe')), amount: @js(old('amount', '')), installments: @js((int) old('installments', 1)), busy: false }" @submit="busy = true">
        @csrf
        <x-ui.card>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select name="employee_id" label="Employee" :options="$employees" :value="$employeeId" placeholder="Choose…" required class="sm:col-span-2" />
                <x-ui.money-input name="amount" label="Amount" required x-model="amount" />
                <x-ui.input name="installments" label="Recover over (months)" type="number" min="1" max="24" required :value="1" x-model.number="installments" />
                <p class="text-sm text-gray-600 sm:col-span-2" x-show="Number(amount) > 0 && installments > 0">
                    About Rs. <span class="font-semibold" x-text="(Math.ceil(Number(amount) * 100 / installments) / 100).toFixed(2)"></span> a month.
                </p>
                <x-ui.select name="paid_from" label="Paid from" :options="$sources" required x-model="from" />
                <div x-show="from === 'bank'" x-cloak>
                    <x-ui.select name="bank_account_id" label="Bank account" :options="$banks" placeholder="Choose…" />
                </div>
                <x-ui.date-input name="date" label="Date" :value="old('date', today()->toDateString())" required hint="From the drawer it is always today." />
                <x-ui.input name="note" label="Note" maxlength="255" class="sm:col-span-2" />
            </div>

            <x-slot:footer>
                <x-ui.button variant="secondary" :href="route('hr.advances.index')">Cancel</x-ui.button>
                <x-ui.button type="submit" x-bind:disabled="busy">Give advance</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
@endsection
