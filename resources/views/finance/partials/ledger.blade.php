{{-- Account ledger with running balance. $opening, $closing, $lines (from FinancialReports::ledger), $from, $to --}}
@php $money = fn ($value) => number_format((float) (string) $value, 2); @endphp

<x-ui.table>
    <x-slot:head>
        <th>Date</th>
        <th>Entry</th>
        <th>Description</th>
        <th class="text-right">Debit</th>
        <th class="text-right">Credit</th>
        <th class="text-right">Balance</th>
    </x-slot:head>
    <tr class="bg-gray-50">
        <td class="text-gray-600">{{ $from->format('Y-m-d') }}</td>
        <td></td>
        <td class="font-medium">Balance brought forward</td>
        <td colspan="2"></td>
        <td class="text-right tabular font-medium">{{ $money($opening) }}</td>
    </tr>
    @forelse ($lines as $row)
        @php $line = $row['line']; $link = $line->entry->sourceLink(); @endphp
        <tr>
            <td class="whitespace-nowrap text-gray-600">{{ $line->entry->date->format('Y-m-d') }}</td>
            <td><a href="{{ route('finance.journal.show', $line->journal_entry_id) }}" class="font-mono text-xs text-brand-700 hover:underline">{{ $line->entry->number }}</a></td>
            <td>
                {{ $line->entry->description }}
                @if ($link && $link['url'])<a href="{{ $link['url'] }}" class="text-xs text-brand-700 hover:underline">{{ $link['label'] }}</a>@endif
                @if ($line->memo)<span class="text-xs text-gray-500">· {{ $line->memo }}</span>@endif
            </td>
            <td class="text-right tabular">{{ (float) $line->debit ? $money($line->debit) : '' }}</td>
            <td class="text-right tabular">{{ (float) $line->credit ? $money($line->credit) : '' }}</td>
            <td class="text-right tabular">{{ $money($row['balance']) }}</td>
        </tr>
    @empty
        <tr><td colspan="6" class="text-center text-gray-500">Nothing posted in this period.</td></tr>
    @endforelse
    <tr class="bg-gray-50">
        <td class="text-gray-600">{{ $to->format('Y-m-d') }}</td>
        <td></td>
        <td class="font-medium">Balance</td>
        <td colspan="2"></td>
        <td class="text-right tabular font-semibold">{{ $money($closing) }}</td>
    </tr>
</x-ui.table>
