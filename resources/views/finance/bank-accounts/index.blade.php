@extends('layouts.app')

@php $money = fn ($value) => 'Rs. '.number_format((float) (string) $value, 2); @endphp

@section('title', 'Banking')

@section('content')
    <x-ui.page-header title="Banking" description="Bank balances as you entered them, cash taken home, and money not yet in the bank.">
        <x-ui.button variant="secondary" :href="route('finance.money.create')">Move money</x-ui.button>
        <x-ui.button :href="route('finance.bank-accounts.create')">Add bank account</x-ui.button>
    </x-ui.page-header>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat-tile label="In the banks" :value="$money($totalBank)" :hint="$banks->where('is_active', true)->count().' active accounts'" />
        <x-ui.stat-tile label="Cash at home" :value="$money($safe)" :href="route('finance.reports.cash-book')" :hint="(float) (string) $safe < 0 ? 'Below zero: record the cash you had at home on the first day (Move money → from Owner\'s own money to Cash at home).' : 'Counted drawer cash goes home at closing; the morning float comes from it.'" />
        <x-ui.stat-tile label="Cash drawer" :value="$money($drawer)" :href="route('finance.reports.cash-book', ['account' => 'drawer'])" :hint="$drawerOpen ? 'Drawer open' : 'Drawer closed'" />
        <x-ui.stat-tile label="Cheques in hand" :value="$money($chequesInHand)" :href="route('finance.cheques.index', ['filter' => ['status' => 'open']])" :hint="$receivedDue ? $receivedDue.' ready to deposit' : 'Received, not yet cleared'" />
    </div>

    @if ((float) (string) $clearing !== 0.0)
        <x-ui.alert type="info" class="mb-4">
            Rs. {{ number_format((float) (string) $clearing, 2) }} of card and bank-transfer payments is not in a bank account yet.
            When it shows on the bank statement, <a href="{{ route('finance.money.create', ['from' => 'clearing']) }}" class="font-medium underline">move it to the bank</a>.
        </x-ui.alert>
    @endif

    @if ($banks->isEmpty())
        <x-ui.empty-state title="No bank accounts yet" description="Add the shop's bank accounts with the balance on the day you start." />
    @else
        <x-ui.table>
            <x-slot:head>
                <th>Bank</th>
                <th>Account</th>
                <th>Type</th>
                <th></th>
                <th class="text-right">Balance</th>
            </x-slot:head>
            @foreach ($banks as $bank)
                <tr @class(['opacity-60' => ! $bank->is_active])>
                    <td>
                        <a href="{{ route('finance.bank-accounts.show', $bank) }}" class="font-medium text-brand-700 hover:underline">{{ $bank->bank_name }}</a>
                        <span class="text-xs text-gray-500">{{ $bank->branch }}</span>
                    </td>
                    <td class="font-mono">{{ $bank->account_no }}</td>
                    <td>{{ $bank->type->label() }}</td>
                    <td>
                        @if ($bank->receives_card_payments)<x-ui.badge color="blue">Card &amp; transfers</x-ui.badge>@endif
                        @unless ($bank->is_active)<x-ui.badge>Inactive</x-ui.badge>@endunless
                    </td>
                    <td class="text-right tabular">{{ number_format((float) (string) $balances[$bank->id], 2) }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    @endif

    <p class="mt-4 text-sm text-gray-500">
        Issued cheques not yet cleared: {{ $money($chequesIssued) }}.
        <a href="{{ route('finance.cheques.calendar') }}" class="text-brand-700 hover:underline">Cheque calendar</a>
    </p>
@endsection
