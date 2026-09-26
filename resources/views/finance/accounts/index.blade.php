@extends('layouts.app')

@section('title', 'Chart of accounts')

@section('content')
    <x-ui.page-header title="Chart of accounts" description="Every account the journal posts to, with its balance today. Accounts marked 'system' are posted automatically.">
        <x-ui.button :href="route('finance.accounts.create')">Add account</x-ui.button>
    </x-ui.page-header>

    <div class="space-y-6">
        @foreach ($types as $type)
            @php $rows = $accounts[$type->value] ?? collect(); @endphp
            @if ($rows->isNotEmpty())
                <x-ui.card :title="['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expenses'][$type->value]">
                    <table class="w-full text-sm">
                        @foreach ($rows as $account)
                            @php
                                $amount = $net[$account->id] ?? null;
                                $balance = $amount === null ? null : ($type->isDebitNormal() ? $amount : $amount->negated());
                            @endphp
                            <tr @class(['border-t border-gray-100', 'text-gray-400' => ! $account->is_active])>
                                <td class="w-20 py-1.5 font-mono">{{ $account->code }}</td>
                                <td class="py-1.5"><a href="{{ route('finance.accounts.show', $account) }}" class="text-brand-700 hover:underline">{{ $account->name }}</a></td>
                                <td class="py-1.5">@if ($account->is_system)<x-ui.badge>system</x-ui.badge>@endif</td>
                                <td class="w-40 py-1.5 text-right tabular">{{ $balance === null ? '—' : number_format((float) (string) $balance, 2) }}</td>
                            </tr>
                        @endforeach
                    </table>
                </x-ui.card>
            @endif
        @endforeach
    </div>
@endsection
