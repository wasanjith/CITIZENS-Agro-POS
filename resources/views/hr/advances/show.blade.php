@extends('layouts.app')

@section('title', $advance->number)

@section('content')
    <x-ui.page-header :title="$advance->number" :description="$advance->employee->full_name.' · '.$advance->date->format('Y-m-d')">
        @if ($advance->status === \App\Domain\HR\Enums\SalaryAdvanceStatus::Active && $advance->recoveries->isEmpty())
            <x-ui.button variant="secondary" class="text-red-700" x-data x-on:click="$dispatch('open-modal', 'cancel-advance')">Cancel advance</x-ui.button>
        @endif
        <x-ui.button variant="secondary" :href="route('hr.advances.index')">All advances</x-ui.button>
    </x-ui.page-header>

    @error('reason')<x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>@enderror

    @if ($advance->cancelled_at)
        <x-ui.alert type="warning" class="mb-4">Cancelled on {{ $advance->cancelled_at->format('Y-m-d H:i') }} by {{ $advance->cancelledBy?->name }}: {{ $advance->cancel_reason }}</x-ui.alert>
    @endif

    <div class="mb-6 grid gap-4 sm:grid-cols-4">
        <x-ui.stat-tile label="Amount" :value="'Rs. '.number_format((float) $advance->amount, 2)" :hint="$advance->paid_from->label().($advance->bankAccount ? ' · '.$advance->bankAccount->displayName() : '')" />
        <x-ui.stat-tile label="Installment" :value="'Rs. '.number_format((float) $advance->installment_amount, 2)" :hint="$advance->installments.' '.str('month')->plural($advance->installments)" />
        <x-ui.stat-tile label="Recovered" :value="'Rs. '.number_format((float) $advance->recovered_amount, 2)" />
        <x-ui.stat-tile label="Status" :value="$advance->status->label()" :hint="'Given by '.$advance->createdBy->name" />
    </div>

    <x-ui.card title="Recovered from payslips">
        @forelse ($advance->recoveries as $recovery)
            <div class="flex justify-between py-1 text-sm">
                <a href="{{ route('hr.payslips.show', $recovery->payslip) }}" class="text-brand-700 hover:underline">{{ $recovery->payslip->payrollRun->label() }}</a>
                <span class="tabular">Rs. {{ number_format((float) $recovery->amount, 2) }}</span>
            </div>
        @empty
            <p class="text-sm text-gray-500">Nothing yet. The next approved payroll takes the first installment.</p>
        @endforelse
        @if ($advance->note)<p class="mt-3 text-sm text-gray-600">Note: {{ $advance->note }}</p>@endif
    </x-ui.card>

    @include('finance.partials.entries', ['entries' => $entries])

    <x-ui.modal name="cancel-advance" title="Cancel {{ $advance->number }}?" max-width="md">
        <form method="POST" action="{{ route('hr.advances.cancel', $advance) }}" id="cancel-advance-form" class="grid gap-4">
            @csrf
            <p class="text-sm text-gray-600">Only when the money was not given or has come back in full. It goes back where it came from (drawer cash only while that drawer is open, otherwise to the cash at home).</p>
            <x-ui.input name="reason" label="Reason" required maxlength="200" />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'cancel-advance')">Back</x-ui.button>
            <x-ui.button type="submit" variant="danger" form="cancel-advance-form">Cancel advance</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
@endsection
