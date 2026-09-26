@extends('layouts.app')

@section('title', $expense->number)

@section('content')
    <x-ui.page-header :title="$expense->number" :description="$expense->category->name.' · '.$expense->date->format('Y-m-d')">
        @if (! $expense->isCancelled())
            @can('cancel', \App\Domain\Finance\Models\Expense::class)
                <x-ui.button variant="secondary" class="text-red-700" x-data x-on:click="$dispatch('open-modal', 'cancel-expense')">Cancel expense</x-ui.button>
            @endcan
        @endif
        <x-ui.button variant="secondary" :href="route('finance.expenses.index')">All expenses</x-ui.button>
    </x-ui.page-header>

    @error('reason')<x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>@enderror

    @if ($expense->isCancelled())
        <x-ui.alert type="warning" class="mb-4">Cancelled on {{ $expense->cancelled_at->format('Y-m-d H:i') }} by {{ $expense->cancelledBy?->name }}: {{ $expense->cancel_reason }}</x-ui.alert>
    @endif

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-ui.stat-tile label="Amount" :value="'Rs. '.number_format((float) $expense->amount, 2)" />
        <x-ui.stat-tile label="Paid from" :value="$expense->paid_from->label()" :hint="$expense->bankAccount?->displayName()" />
        <x-ui.stat-tile label="Recorded by" :value="$expense->createdBy->name" :hint="$expense->created_at->format('Y-m-d H:i')" />
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card title="Details">
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <dt class="text-gray-500">Paid to</dt><dd>{{ $expense->payee ?: '—' }}</dd>
                <dt class="text-gray-500">Bill / reference</dt><dd>{{ $expense->reference ?: '—' }}</dd>
                <dt class="text-gray-500">Note</dt><dd>{{ $expense->note ?: '—' }}</dd>
                <dt class="text-gray-500">Account</dt><dd>{{ $expense->category->account->displayName() }}</dd>
            </dl>
        </x-ui.card>

        <x-ui.card title="Bill">
            @if ($expense->receipt_path)
                @if (str_ends_with(strtolower($expense->receipt_path), '.pdf'))
                    <a href="{{ route('finance.expenses.receipt', $expense) }}" target="_blank" class="text-brand-700 hover:underline">Open the PDF</a>
                @else
                    <a href="{{ route('finance.expenses.receipt', $expense) }}" target="_blank"><img src="{{ route('finance.expenses.receipt', $expense) }}" alt="Bill for {{ $expense->number }}" class="max-h-96 rounded ring-1 ring-gray-200"></a>
                @endif
            @else
                <p class="text-sm text-gray-500">No photo attached.</p>
            @endif
        </x-ui.card>
    </div>

    @include('finance.partials.entries', ['entries' => $entries])

    <x-ui.modal name="cancel-expense" title="Cancel {{ $expense->number }}?" max-width="md">
        <form method="POST" action="{{ route('finance.expenses.cancel', $expense) }}" id="cancel-expense-form" class="grid gap-4">
            @csrf
            <p class="text-sm text-gray-600">
                The money goes back where it came from{{ $expense->paid_from === \App\Domain\Finance\Enums\PaidFrom::CashDrawer ? ' (a pay in to the drawer; the drawer must still be open)' : '' }} and the accounts are reversed. The expense stays in the list, marked cancelled.
            </p>
            <x-ui.input name="reason" label="Reason" required maxlength="200" />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'cancel-expense')">Back</x-ui.button>
            <x-ui.button type="submit" variant="danger" form="cancel-expense-form">Cancel expense</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
@endsection
