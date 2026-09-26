@extends('layouts.app')

@section('title', 'Cash book')

@section('content')
    <x-ui.page-header title="Cash book" :description="$account->name.' · '.$from->format('Y-m-d').' to '.$to->format('Y-m-d')">
        <x-ui.button variant="secondary" x-data x-on:click="window.print()" class="print:hidden">Print</x-ui.button>
    </x-ui.page-header>

    @include('finance.reports.tabs', ['active' => 'cash-book'])

    <form method="GET" action="{{ route('finance.reports.cash-book') }}" class="mb-4 flex flex-wrap items-end gap-3 print:hidden">
        <x-ui.select name="account" label="Cash" :options="$accounts" :value="$which === \App\Domain\Finance\Enums\SystemAccount::CashDrawer ? 'drawer' : 'safe'" />
        <x-ui.date-input name="from" label="From" :value="$from->toDateString()" />
        <x-ui.date-input name="to" label="To" :value="$to->toDateString()" />
        <x-ui.button type="submit" variant="secondary">Show</x-ui.button>
    </form>

    @include('finance.partials.ledger', ['opening' => $opening, 'closing' => $closing, 'lines' => $lines, 'from' => $from, 'to' => $to])
@endsection
