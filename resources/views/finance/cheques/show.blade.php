@extends('layouts.app')

@php
    use App\Domain\Finance\Enums\ChequeDirection;
    use App\Domain\Finance\Enums\ChequeStatus;
    $received = $cheque->direction === ChequeDirection::Received;
    $source = $cheque->source;
@endphp

@section('title', 'Cheque '.$cheque->number)

@section('content')
    <x-ui.page-header :title="'Cheque '.$cheque->number" :description="($received ? 'Received from ' : 'Issued to ').$cheque->partyLabel()">
        @if ($received && $cheque->status === ChequeStatus::Pending)
            <x-ui.button x-data x-on:click="$dispatch('open-modal', 'cheque-deposit')">Deposit</x-ui.button>
        @endif
        @if (($received && $cheque->status === ChequeStatus::Deposited) || (! $received && $cheque->status === ChequeStatus::Pending))
            <x-ui.button x-data x-on:click="$dispatch('open-modal', 'cheque-clear')">Cleared</x-ui.button>
        @endif
        @if (($received && in_array($cheque->status, [ChequeStatus::Pending, ChequeStatus::Deposited, ChequeStatus::Cleared], true)) || (! $received && $cheque->status === ChequeStatus::Pending))
            <x-ui.button variant="secondary" class="text-red-700" x-data x-on:click="$dispatch('open-modal', 'cheque-bounce')">Bounced</x-ui.button>
        @endif
        @if (! $received && $cheque->status === ChequeStatus::Pending)
            <x-ui.button variant="secondary" x-data x-on:click="$dispatch('open-modal', 'cheque-cancel')">Cancel cheque</x-ui.button>
        @endif
    </x-ui.page-header>

    @foreach (['cheque', 'date', 'reason', 'bank_account_id', 'number', 'cheque_date'] as $field)
        @error($field)<x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>@enderror
    @endforeach

    <div class="mb-6 grid gap-4 sm:grid-cols-4">
        <x-ui.stat-tile label="Amount" :value="'Rs. '.number_format((float) $cheque->amount, 2)" />
        <x-ui.stat-tile label="Status" :value="$cheque->status->label()" :hint="$cheque->bounced_on ? 'on '.$cheque->bounced_on->format('Y-m-d') : ($cheque->cleared_on ? 'on '.$cheque->cleared_on->format('Y-m-d') : null)" />
        <x-ui.stat-tile label="Cheque date" :value="$cheque->cheque_date->format('Y-m-d')" :hint="$cheque->isPostDated() ? 'Post-dated' : null" />
        <x-ui.stat-tile :label="$received ? 'Deposited to' : 'Drawn on'" :value="$cheque->bankAccount?->displayName() ?? '—'" :hint="$cheque->deposited_on ? 'on '.$cheque->deposited_on->format('Y-m-d') : null" />
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card title="Details">
            @if ($cheque->status === ChequeStatus::Pending)
                <form method="POST" action="{{ route('finance.cheques.update', $cheque) }}" class="grid gap-4 sm:grid-cols-2">
                    @csrf
                    @method('PUT')
                    <x-ui.input name="number" label="Cheque number" :value="$cheque->number" required maxlength="30" />
                    <x-ui.date-input name="cheque_date" label="Cheque date" :value="$cheque->cheque_date->toDateString()" required />
                    <x-ui.input name="bank_name" label="Bank" :value="$cheque->bank_name" maxlength="100" />
                    <x-ui.input name="branch" label="Branch" :value="$cheque->branch" maxlength="100" />
                    <x-ui.input name="note" label="Note" :value="$cheque->note" maxlength="255" class="sm:col-span-2" />
                    <div class="sm:col-span-2"><x-ui.button type="submit" variant="secondary">Save details</x-ui.button></div>
                </form>
            @else
                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <dt class="text-gray-500">Bank</dt><dd>{{ $cheque->bank_name ?: '—' }} {{ $cheque->branch }}</dd>
                    <dt class="text-gray-500">Note</dt><dd>{{ $cheque->note ?: '—' }}</dd>
                </dl>
            @endif
            <p class="mt-4 text-sm text-gray-600">
                For:
                @if ($source instanceof \App\Domain\Inventory\Support\StockReference)
                    <a href="{{ $source->referenceUrl() }}" class="font-mono text-brand-700 hover:underline">{{ $source->referenceLabel() }}</a>
                @else
                    —
                @endif
            </p>
        </x-ui.card>

        <x-ui.card title="History">
            <ol class="space-y-2 text-sm">
                @foreach ($cheque->status_history ?? [] as $step)
                    <li class="flex gap-3">
                        <span class="whitespace-nowrap text-gray-500">{{ $step['at'] }}</span>
                        <span class="font-medium">{{ \App\Domain\Finance\Enums\ChequeStatus::tryFrom($step['status'])?->label() }}</span>
                        <span class="text-gray-600">{{ $step['note'] }}</span>
                    </li>
                @endforeach
            </ol>
        </x-ui.card>
    </div>

    @include('finance.partials.entries', ['entries' => $entries])

    <x-ui.modal name="cheque-deposit" title="Deposit cheque {{ $cheque->number }}" max-width="md">
        <form method="POST" action="{{ route('finance.cheques.deposit', $cheque) }}" id="cheque-deposit-form" class="grid gap-4">
            @csrf
            <x-ui.select name="bank_account_id" label="Bank account" :options="$banks" required placeholder="Choose…" />
            <x-ui.date-input name="date" label="Deposited on" :value="today()->toDateString()" required />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'cheque-deposit')">Cancel</x-ui.button>
            <x-ui.button type="submit" form="cheque-deposit-form">Deposit</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="cheque-clear" title="Cheque {{ $cheque->number }} cleared" max-width="md">
        <form method="POST" action="{{ route('finance.cheques.clear', $cheque) }}" id="cheque-clear-form" class="grid gap-4">
            @csrf
            <p class="text-sm text-gray-600">{{ $received ? 'The money is added to' : 'The money is taken from' }} {{ $cheque->bankAccount?->displayName() }}.</p>
            <x-ui.date-input name="date" label="Cleared on" :value="today()->toDateString()" required />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'cheque-clear')">Cancel</x-ui.button>
            <x-ui.button type="submit" form="cheque-clear-form">Mark cleared</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    @foreach (['bounce' => 'Cheque '.$cheque->number.' bounced', 'cancel' => 'Cancel cheque '.$cheque->number] as $kind => $title)
        <x-ui.modal :name="'cheque-'.$kind" :title="$title" max-width="md">
            <form method="POST" action="{{ route('finance.cheques.'.$kind, $cheque) }}" id="cheque-{{ $kind }}-form" class="grid gap-4">
                @csrf
                <p class="text-sm text-gray-600">
                    @if ($received)
                        {{ $cheque->partyLabel() }} will owe Rs. {{ number_format((float) $cheque->amount, 2) }} again; the invoices this cheque paid become unpaid.
                    @else
                        The shop will owe {{ $cheque->partyLabel() }} Rs. {{ number_format((float) $cheque->amount, 2) }} again; the goods receipts it paid become unpaid.
                    @endif
                </p>
                <x-ui.date-input name="date" label="Date" :value="today()->toDateString()" required />
                <x-ui.input name="reason" label="Reason" required maxlength="200" :placeholder="$kind === 'bounce' ? 'Insufficient funds' : 'Written wrongly'" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'cheque-{{ $kind }}')">Back</x-ui.button>
                <x-ui.button type="submit" variant="danger" form="cheque-{{ $kind }}-form">{{ $kind === 'bounce' ? 'Mark bounced' : 'Cancel cheque' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endforeach
@endsection
