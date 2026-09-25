@extends('layouts.app')

@php $money = fn ($value) => number_format((float) $value, 2); @endphp

@section('title', $payment->number)

@section('content')
    <x-ui.page-header :title="'Payment '.$payment->number" :description="$payment->customer->name.' · '.$payment->created_at->format('Y-m-d H:i')">
        <x-ui.button variant="secondary" :href="route('pos.customer-payments.receipt', $payment)" target="_blank">80 mm</x-ui.button>
        @if ($canReprint)
            <form method="POST" action="{{ route('pos.customer-payments.reprint', $payment) }}">
                @csrf
                <x-ui.button type="submit" variant="secondary">Reprint (COPY)</x-ui.button>
            </form>
        @endif
        <x-ui.button :href="route('customers.show', $payment->customer)">Customer</x-ui.button>
    </x-ui.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Payment">
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between"><dt>Amount</dt><dd class="text-lg font-semibold tabular">{{ $money($payment->amount) }}</dd></div>
                <div class="flex justify-between"><dt>Method</dt><dd>{{ $payment->method->label() }}</dd></div>
                @if ($payment->reference)<div class="flex justify-between"><dt>Reference</dt><dd>{{ $payment->reference }}</dd></div>@endif
                <div class="flex justify-between"><dt>Received by</dt><dd>{{ $payment->receivedBy->name }}</dd></div>
                <div class="flex justify-between"><dt>Terminal</dt><dd>{{ $payment->terminal?->displayName() ?? '—' }}</dd></div>
                @if ($payment->note)<div class="pt-2 text-gray-600">{{ $payment->note }}</div>@endif
            </dl>
        </x-ui.card>

        <x-ui.card title="Applied to" class="lg:col-span-2">
            @php $advance = (float) $payment->amount - (float) $payment->allocations->sum('amount'); @endphp
            @forelse ($payment->allocations as $allocation)
                <div class="flex justify-between py-1 text-sm">
                    <a href="{{ route('sales.show', $allocation->sale) }}" class="font-mono text-brand-700 hover:underline">{{ $allocation->sale->invoice_no }}</a>
                    <span class="tabular">{{ $money($allocation->amount) }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-500">Not applied to any invoice.</p>
            @endforelse
            @if ($advance > 0.004)
                <div class="mt-2 flex justify-between border-t border-gray-200 pt-2 text-sm"><span>Kept as advance</span><span class="tabular">{{ $money($advance) }}</span></div>
            @endif
        </x-ui.card>
    </div>
@endsection
