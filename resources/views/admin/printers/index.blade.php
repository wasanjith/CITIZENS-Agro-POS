@extends('layouts.app')

@section('title', 'Printers')

@section('content')
    <x-ui.page-header title="Printers" description="One 80 mm USB thermal printer per terminal. Only the main cashier printer has the cash drawer.">
        <x-ui.button variant="secondary" :href="route('admin.print-jobs.index')">Print log</x-ui.button>
        <x-ui.button variant="secondary" :href="route('admin.printing-test')">Printing test</x-ui.button>
        <x-ui.button :href="route('admin.printers.create')">Add printer</x-ui.button>
    </x-ui.page-header>

    @error('printer')
        <x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    @if ($printers->isEmpty())
        <x-ui.empty-state title="No printers yet" />
    @else
        <x-ui.table>
            <x-slot:head>
                <th>Printer</th>
                <th>Terminal</th>
                <th>Windows printer name</th>
                <th>Paper</th>
                <th>Cash drawer</th>
                <th>Last test</th>
                <th><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($printers as $printer)
                <tr>
                    <td>
                        <p class="font-medium">{{ $printer->name }}</p>
                        <p class="text-xs text-gray-500">{{ $printer->model ?: 'Model not set' }} @unless ($printer->is_active) · <span class="text-red-600">inactive</span> @endunless</p>
                    </td>
                    <td>
                        <form method="POST" action="{{ route('admin.printers.assign', $printer) }}" class="flex items-center gap-1">
                            @csrf
                            <label class="sr-only" for="assign-{{ $printer->id }}">Terminal of {{ $printer->name }}</label>
                            <select id="assign-{{ $printer->id }}" name="terminal_id" class="rounded-md border-gray-300 py-1 text-sm" onchange="if (confirm('Move {{ e($printer->name) }} to this terminal? Its current printer becomes a spare.')) this.form.submit(); else this.value = '{{ $printer->terminal_id }}'">
                                <option value="" @selected($printer->terminal_id === null)>Spare (not assigned)</option>
                                @foreach ($terminals as $terminal)
                                    <option value="{{ $terminal->id }}" @selected($printer->terminal_id === $terminal->id)>{{ $terminal->displayName() }}</option>
                                @endforeach
                            </select>
                        </form>
                    </td>
                    <td class="text-gray-600">{{ $printer->windows_name ?: '—' }}</td>
                    <td class="tabular">{{ $printer->paper_width_mm }} mm · {{ $printer->dpi }} dpi</td>
                    <td>{!! $printer->has_cash_drawer ? '<span class="font-medium text-amber-800">Yes</span>' : '—' !!}</td>
                    <td class="text-gray-600">{{ $printer->last_test_at?->diffForHumans() ?? 'Never' }}</td>
                    <td class="text-right">
                        <div class="flex items-center justify-end gap-3">
                            @if ($printer->terminal_id)
                                <form method="POST" action="{{ route('admin.printers.test', $printer) }}">
                                    @csrf
                                    <x-ui.button type="submit" variant="link">Test print</x-ui.button>
                                </form>
                            @endif
                            <x-ui.button variant="link" :href="route('admin.printers.edit', $printer)">Edit</x-ui.button>
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    @endif
@endsection
