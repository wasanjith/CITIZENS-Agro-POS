@extends('layouts.app')

@section('title', 'Units')

@section('content')
    <x-ui.page-header title="Units" description="Units of measure. How many base units a bag or packet holds is set on each product.">
        <x-ui.button :href="route('catalog.units.create')">Add unit</x-ui.button>
    </x-ui.page-header>

    <x-ui.table>
        <x-slot:head>
            <th>Unit</th>
            <th>Sinhala</th>
            <th>Symbol</th>
            <th>Decimals</th>
            <th><span class="sr-only">Actions</span></th>
        </x-slot:head>

        @foreach ($units as $unit)
            <tr>
                <td class="font-medium">{{ $unit->name }}</td>
                <td class="font-sinhala text-gray-600">{{ $unit->name_si }}</td>
                <td class="text-gray-600">{{ $unit->symbol }}</td>
                <td>{{ $unit->allows_decimal ? 'Yes (e.g. 2.5)' : 'Whole numbers' }}</td>
                <td class="text-right">
                    <x-ui.button variant="link" :href="route('catalog.units.edit', $unit)">Edit</x-ui.button>
                </td>
            </tr>
        @endforeach
    </x-ui.table>
@endsection
