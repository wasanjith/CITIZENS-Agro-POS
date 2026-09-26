@extends('layouts.app')

@section('title', 'Journal')

@section('content')
    <x-ui.page-header title="Journal" description="Every entry the system posted. Entries are never changed; mistakes are undone with a reversal." />

    <form method="GET" action="{{ route('finance.journal.index') }}" class="mb-4 flex flex-wrap items-end gap-3">
        <x-ui.input name="search" label="Search" :value="request('search')" placeholder="JE number or description" />
        <x-ui.date-input name="from" label="From" :value="$from->toDateString()" />
        <x-ui.date-input name="to" label="To" :value="$to->toDateString()" />
        <x-ui.button type="submit" variant="secondary">Show</x-ui.button>
    </form>

    @if ($entries->isEmpty())
        <x-ui.empty-state title="No entries in this period" />
    @else
        <div class="space-y-3">
            @foreach ($entries as $entry)
                <x-ui.card>
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-sm">
                            <a href="{{ route('finance.journal.show', $entry) }}" class="font-mono font-medium text-brand-700 hover:underline">{{ $entry->number }}</a>
                            <span class="text-gray-500">{{ $entry->date->format('Y-m-d') }}</span>
                            {{ $entry->description }}
                            @if ($entry->reverses_id)<x-ui.badge color="amber">reversal</x-ui.badge>@endif
                        </p>
                        <span class="text-xs text-gray-500">{{ $entry->createdBy?->name }}</span>
                    </div>
                    <table class="mt-2 w-full text-sm">
                        @foreach ($entry->lines as $line)
                            <tr>
                                <td @class(['py-0.5', 'pl-6' => (float) $line->credit > 0])>{{ $line->account->displayName() }}</td>
                                <td class="w-36 text-right tabular">{{ (float) $line->debit ? number_format((float) $line->debit, 2) : '' }}</td>
                                <td class="w-36 text-right tabular">{{ (float) $line->credit ? number_format((float) $line->credit, 2) : '' }}</td>
                            </tr>
                        @endforeach
                    </table>
                </x-ui.card>
            @endforeach
        </div>
        <x-ui.pagination :paginator="$entries" />
    @endif
@endsection
