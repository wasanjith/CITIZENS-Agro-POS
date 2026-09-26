{{-- Journal entries a document posted. $entries: Collection<JournalEntry> with lines.account --}}
@if ($entries->isNotEmpty())
    <x-ui.card title="Journal" description="How this was recorded in the accounts." class="mt-6">
        <div class="space-y-4">
            @foreach ($entries as $entry)
                <div>
                    <p class="text-sm">
                        @can('finance.journal.view')
                            <a href="{{ route('finance.journal.show', $entry) }}" class="font-mono text-brand-700 hover:underline">{{ $entry->number }}</a>
                        @else
                            <span class="font-mono">{{ $entry->number }}</span>
                        @endcan
                        <span class="text-gray-500">· {{ $entry->date->format('Y-m-d') }} · {{ $entry->description }}</span>
                    </p>
                    <table class="mt-1 w-full text-sm">
                        @foreach ($entry->lines as $line)
                            <tr>
                                <td @class(['py-0.5', 'pl-6' => (float) $line->credit > 0])>{{ $line->account->displayName() }}</td>
                                <td class="w-32 text-right tabular">{{ (float) $line->debit ? number_format((float) $line->debit, 2) : '' }}</td>
                                <td class="w-32 text-right tabular">{{ (float) $line->credit ? number_format((float) $line->credit, 2) : '' }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @endforeach
        </div>
    </x-ui.card>
@endif
