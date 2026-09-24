@extends('layouts.app')

@section('title', 'Check import')

@section('content')
    <x-ui.page-header title="Check import" :description="$fileName">
        <x-ui.button variant="secondary" :href="route('catalog.products.import')">Upload another file</x-ui.button>
    </x-ui.page-header>

    @if ($missingColumns)
        <x-ui.alert type="error" class="mb-6">
            The file is missing the column(s) <strong>{{ implode(', ', $missingColumns) }}</strong>. Start from the template and keep its first row.
        </x-ui.alert>
    @else
        <div class="mb-6 grid gap-4 sm:grid-cols-2">
            <x-ui.stat-tile label="Rows ready to import" :value="count($valid)" />
            <x-ui.stat-tile label="Rows with problems" :value="count($rowErrors)" />
        </div>

        @if ($rowErrors)
            <x-ui.alert type="error" class="mb-6">
                Nothing can be imported until every row is correct. Fix the rows below in Excel and upload the file again.
            </x-ui.alert>

            <x-ui.card title="Problems" class="mb-6">
                <x-ui.table class="shadow-none ring-0">
                    <x-slot:head>
                        <th>Row</th>
                        <th>Problem</th>
                    </x-slot:head>
                    @foreach ($rowErrors as $row => $messages)
                        <tr>
                            <td class="whitespace-nowrap font-mono font-semibold">Row {{ $row }}</td>
                            <td>
                                <ul class="list-disc pl-4 text-red-800">
                                    @foreach ($messages as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @else
            <form method="POST" action="{{ route('catalog.products.import.store', $token) }}" class="mb-6">
                @csrf
                <x-ui.button type="submit">Import {{ count($valid) }} products</x-ui.button>
            </form>
        @endif

        @if ($valid)
            <x-ui.card title="Rows ready to import">
                <x-ui.table class="shadow-none ring-0">
                    <x-slot:head>
                        <th>Row</th>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Category</th>
                        <th>Units</th>
                        <th>Opening stock</th>
                    </x-slot:head>
                    @foreach (array_slice($valid, 0, 200, true) as $row => $product)
                        <tr>
                            <td class="text-gray-500">{{ $row }}</td>
                            <td class="font-mono font-semibold">{{ $product['short_code'] }}</td>
                            <td>
                                {{ $product['name'] }}
                                @if ($product['name_si'])
                                    <p class="font-sinhala text-xs text-gray-600">{{ $product['name_si'] }}</p>
                                @endif
                            </td>
                            <td class="text-gray-600">{{ $product['category_path'] }}</td>
                            <td class="text-gray-600">{{ $product['base_unit_name'] }}@if (count($product['units']) > 1) + {{ count($product['units']) - 1 }} more @endif</td>
                            <td class="tabular text-gray-600">{{ $product['opening_stock']['qty'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
                @if (count($valid) > 200)
                    <p class="mt-3 text-sm text-gray-500">Showing the first 200 of {{ count($valid) }} rows.</p>
                @endif
            </x-ui.card>
        @endif
    @endif
@endsection
