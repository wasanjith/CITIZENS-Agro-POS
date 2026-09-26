@extends('layouts.app')

@section('title', $payment->number)

@section('content')
    <x-ui.page-header :title="$payment->number" :description="'To '.$payment->supplier->name.' · '.$payment->date->format('Y-m-d')">
        <x-ui.button variant="secondary" :href="route('purchasing.suppliers.show', $payment->supplier)">Supplier</x-ui.button>
    </x-ui.page-header>

    @if ($payment->reversed_at)
        <x-ui.alert type="warning" class="mb-4">The cheque for this payment bounced or was cancelled on {{ $payment->reversed_at->format('Y-m-d') }}; the supplier is owed this money again.</x-ui.alert>
    @endif

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-ui.stat-tile label="Amount" :value="'Rs. '.number_format((float) $payment->amount, 2)" />
        <x-ui.stat-tile label="Paid by" :value="$payment->paid_from->label()" :hint="$payment->bankAccount?->displayName() ?? $payment->reference" />
        <x-ui.stat-tile label="Entered by" :value="$payment->createdBy->name" :hint="$payment->created_at->format('Y-m-d H:i')" />
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card title="Applied to">
            @if ($payment->allocations->isEmpty())
                <p class="text-sm text-gray-500">Not applied to a goods receipt (advance).</p>
            @else
                <ul class="divide-y divide-gray-100 text-sm">
                    @foreach ($payment->allocations as $allocation)
                        <li class="flex justify-between py-2">
                            <a href="{{ route('purchasing.goods-receipts.show', $allocation->goodsReceipt) }}" class="font-mono text-brand-700 hover:underline">{{ $allocation->goodsReceipt->number }}</a>
                            <span class="tabular">{{ number_format((float) $allocation->amount, 2) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        @if ($payment->cheque)
            <x-ui.card title="Cheque">
                <p class="text-sm">
                    @can('view', $payment->cheque)
                        <a href="{{ route('finance.cheques.show', $payment->cheque) }}" class="font-mono text-brand-700 hover:underline">{{ $payment->cheque->number }}</a>
                    @else
                        <span class="font-mono">{{ $payment->cheque->number }}</span>
                    @endcan
                    dated {{ $payment->cheque->cheque_date->format('Y-m-d') }} ·
                    <x-ui.badge :color="$payment->cheque->status->color()">{{ $payment->cheque->status->label() }}</x-ui.badge>
                </p>
            </x-ui.card>
        @endif
    </div>

    @if ($payment->note)
        <p class="mt-4 text-sm text-gray-600">Note: {{ $payment->note }}</p>
    @endif

    @include('finance.partials.entries', ['entries' => $entries])
@endsection
