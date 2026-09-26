@extends('layouts.app')

@php $link = $entry->sourceLink(); @endphp

@section('title', $entry->number)

@section('content')
    <x-ui.page-header :title="$entry->number" :description="$entry->date->format('Y-m-d').' · '.$entry->description">
        <x-ui.button variant="secondary" :href="route('finance.journal.index')">Journal</x-ui.button>
    </x-ui.page-header>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-ui.stat-tile label="Source" :value="$link['label'] ?? '—'" :href="$link['url'] ?? null" :hint="$entry->event" />
        <x-ui.stat-tile label="Posted by" :value="$entry->createdBy?->name ?? 'System'" :hint="$entry->created_at->format('Y-m-d H:i')" />
        <x-ui.stat-tile label="Reversal" :value="$entry->reverses ? 'Reverses '.$entry->reverses->number : ($entry->reversal ? 'Reversed by '.$entry->reversal->number : '—')" :href="$entry->reverses ? route('finance.journal.show', $entry->reverses) : ($entry->reversal ? route('finance.journal.show', $entry->reversal) : null)" />
    </div>

    <x-ui.table>
        <x-slot:head>
            <th>Account</th>
            <th>Memo</th>
            <th class="text-right">Debit</th>
            <th class="text-right">Credit</th>
        </x-slot:head>
        @foreach ($entry->lines as $line)
            <tr>
                <td><a href="{{ route('finance.accounts.show', $line->account) }}" class="text-brand-700 hover:underline">{{ $line->account->displayName() }}</a></td>
                <td class="text-gray-600">{{ $line->memo }}</td>
                <td class="text-right tabular">{{ (float) $line->debit ? number_format((float) $line->debit, 2) : '' }}</td>
                <td class="text-right tabular">{{ (float) $line->credit ? number_format((float) $line->credit, 2) : '' }}</td>
            </tr>
        @endforeach
        <tr class="bg-gray-50 font-semibold">
            <td colspan="2">Total</td>
            <td class="text-right tabular">{{ number_format((float) $entry->lines->sum('debit'), 2) }}</td>
            <td class="text-right tabular">{{ number_format((float) $entry->lines->sum('credit'), 2) }}</td>
        </tr>
    </x-ui.table>
@endsection
