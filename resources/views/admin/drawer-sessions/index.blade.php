@extends('layouts.app')

@section('title', 'Drawer sessions')

@php
    $money = fn ($value) => $value === null ? '—' : number_format((float) $value, 2);
@endphp

@section('content')
    <x-ui.page-header title="Drawer sessions and handovers" description="Who held the cash drawer, when, and how the count compared with the expected cash.">
        @can('drawer.handover')
            <x-ui.button variant="secondary" :href="route('admin.delegations.index')">Cashier authority</x-ui.button>
        @endcan
    </x-ui.page-header>

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
        <x-ui.date-input name="from" label="From" :value="request('from')" />
        <x-ui.date-input name="to" label="To" :value="request('to')" />
        <x-ui.button type="submit">Filter</x-ui.button>
    </form>

    <x-ui.table>
        <x-slot:head>
            <th>Holder</th>
            <th>Opened</th>
            <th>Closed</th>
            <th>Reason</th>
            <th class="text-right">Float</th>
            <th class="text-right">Expected</th>
            <th class="text-right">Counted</th>
            <th class="text-right">Variance</th>
            <th><span class="sr-only">Reports</span></th>
        </x-slot:head>
        @forelse ($sessions as $session)
            <tr>
                <td>
                    <span class="font-medium">{{ $session->holder->name }}</span>
                    @if ($session->delegation)
                        <span class="block text-xs text-gray-500">delegated by {{ $session->delegation->fromUser->name }}</span>
                    @endif
                    @if ($session->next)
                        <span class="block text-xs text-gray-500">→ {{ $session->next->holder->name }}</span>
                    @endif
                </td>
                <td class="tabular">{{ $session->opened_at->format('Y-m-d H:i') }}</td>
                <td class="tabular">{{ $session->closed_at?->format('H:i') ?? '' }} @if ($session->isOpen())<x-ui.badge color="green">Open</x-ui.badge>@endif</td>
                <td>{{ $session->close_reason?->label() ?? '—' }}</td>
                <td class="text-right tabular">{{ $money($session->opening_float) }}</td>
                <td class="text-right tabular">{{ $money($session->expected_cash) }}</td>
                <td class="text-right tabular">{{ $money($session->counted_cash) }}</td>
                <td @class(['text-right tabular font-semibold', 'text-red-700' => (float) $session->variance < 0, 'text-brand-700' => (float) $session->variance > 0])>{{ $money($session->variance) }}</td>
                <td class="whitespace-nowrap text-right">
                    <a class="text-sm text-brand-700 hover:underline" href="{{ route('pos.drawer.report', $session) }}" target="_blank">View</a>
                    <a class="ml-2 text-sm text-brand-700 hover:underline" href="{{ route('pos.drawer.report-pdf', $session) }}">PDF</a>
                </td>
            </tr>
        @empty
            <tr><td colspan="9" class="text-center text-gray-500">No drawer sessions yet.</td></tr>
        @endforelse
    </x-ui.table>

    <div class="mt-4">{{ $sessions->links() }}</div>
@endsection
