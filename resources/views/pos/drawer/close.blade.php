@extends('layouts.pos')

@section('title', 'Close the day')

@section('content')
    <div class="mx-auto max-w-3xl p-4">
        <x-ui.page-header title="Close the day" :description="'Drawer of '.$session->holder->name.' since '.$session->opened_at->format('H:i').'. Count the cash; the Z report shows the expected amount and the variance.'" />

        @if ($waiting->isNotEmpty())
            <x-ui.alert type="error" class="mb-4" title="Invoices are still waiting for settlement">
                <p>Settle or void them on the cashier screen before closing the day:</p>
                <ul class="mt-2 list-inside list-disc">
                    @foreach ($waiting as $sale)
                        <li>{{ $sale->invoice_no }} · {{ $sale->invoicedTerminal->displayName() }} · Rs. {{ number_format((float) $sale->total, 2) }}</li>
                    @endforeach
                </ul>
                <x-ui.button class="mt-3" size="sm" :href="route('pos.cashier')">Go to the cashier screen</x-ui.button>
            </x-ui.alert>
        @endif

        <form method="POST" action="{{ route('pos.drawer.close.store') }}" class="space-y-4">
            @csrf
            <x-pos.denomination-count :denominations="$denominations" title="Cash in the drawer" />
            <x-ui.input name="note" label="Note (optional)" maxlength="255" />

            <div class="flex justify-end gap-2">
                <x-ui.button variant="secondary" :href="route('pos.drawer.show')">Cancel</x-ui.button>
                <x-ui.button type="submit" size="lg" :disabled="$waiting->isNotEmpty()">Close day and print Z report</x-ui.button>
            </div>
        </form>
    </div>
@endsection
