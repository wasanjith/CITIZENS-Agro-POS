@extends('layouts.app')

@section('title', 'Printers')

@section('content')
    <x-ui.page-header title="Printers" description="One 80 mm USB thermal printer per terminal. Only the main cashier printer has the cash drawer.">
        <x-ui.button variant="secondary" :href="route('admin.printing-test')">Printing test</x-ui.button>
        <x-ui.button :href="route('admin.printers.create')">Add printer</x-ui.button>
    </x-ui.page-header>

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
                    <td>{{ $printer->terminal?->displayName() ?? 'Spare (not assigned)' }}</td>
                    <td class="text-gray-600">{{ $printer->windows_name ?: '—' }}</td>
                    <td class="tabular">{{ $printer->paper_width_mm }} mm · {{ $printer->dpi }} dpi</td>
                    <td>{!! $printer->has_cash_drawer ? '<span class="font-medium text-amber-800">Yes</span>' : '—' !!}</td>
                    <td class="text-gray-600">{{ $printer->last_test_at?->diffForHumans() ?? 'Never' }}</td>
                    <td class="text-right">
                        <x-ui.button variant="link" :href="route('admin.printers.edit', $printer)">Edit</x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    @endif
@endsection
