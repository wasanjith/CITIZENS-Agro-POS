@extends('layouts.app')

@section('title', $account->displayName())

@section('content')
    <x-ui.page-header :title="$account->displayName()" :description="$account->type->label().($account->description ? ' · '.$account->description : '')">
        <x-ui.button variant="secondary" :href="route('finance.accounts.index')">Chart of accounts</x-ui.button>
        <x-ui.button :href="route('finance.accounts.edit', $account)">Edit</x-ui.button>
    </x-ui.page-header>

    @include('finance.partials.period', ['action' => route('finance.accounts.show', $account)])
    @include('finance.partials.ledger', ['opening' => $opening, 'closing' => $closing, 'lines' => $lines, 'from' => $from, 'to' => $to])
@endsection
