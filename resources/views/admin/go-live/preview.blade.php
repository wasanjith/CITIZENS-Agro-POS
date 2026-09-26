@extends('layouts.app')

@section('title', 'Check import')

@php $money = fn ($value) => number_format((float) $value, 2); @endphp

@section('content')
    <x-ui.page-header :title="'Check '.$type.' import'" :description="$fileName">
        <x-ui.button variant="secondary" :href="route('admin.go-live.index')">Upload another file</x-ui.button>
    </x-ui.page-header>

    @if ($missingColumns)
        <x-ui.alert type="error" class="mb-6">
            The file is missing the column(s) <strong>{{ implode(', ', $missingColumns) }}</strong>. Start from the template and keep its first row.
        </x-ui.alert>
    @else
        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <x-ui.stat-tile label="Rows ready to import" :value="count($valid)" />
            <x-ui.stat-tile label="Rows with problems" :value="count($rowErrors)" />
            <x-ui.stat-tile label="Opening balances" :value="'Rs. '.$money(collect($valid)->sum(fn ($row) => (float) $row['opening_balance']))" />
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
            <form method="POST" action="{{ route('admin.go-live.store', ['type' => $type, 'token' => $token]) }}" class="mb-6">
                @csrf
                <x-ui.button type="submit">Import {{ count($valid) }} {{ $type }}</x-ui.button>
                <p class="mt-2 text-sm text-gray-500">Each opening balance is posted to the books (against opening balance equity), the same way the {{ $type === 'customers' ? 'customer' : 'supplier' }} form does.</p>
            </form>
        @endif

        @if ($valid)
            <x-ui.card title="Rows ready to import">
                <x-ui.table class="shadow-none ring-0">
                    <x-slot:head>
                        <th>Row</th>
                        <th>Name</th>
                        <th>Phone</th>
                        @if ($type === 'customers')
                            <th class="text-right">Credit limit</th>
                            <th class="text-right">Credit days</th>
                        @else
                            <th class="text-right">Payment terms (days)</th>
                        @endif
                        <th class="text-right">Opening balance</th>
                    </x-slot:head>
                    @foreach (array_slice($valid, 0, 200, true) as $row => $record)
                        <tr>
                            <td class="text-gray-500">{{ $row }}</td>
                            <td>
                                {{ $record['name'] }}
                                @if (! empty($record['name_si']))
                                    <p class="font-sinhala text-xs text-gray-600">{{ $record['name_si'] }}</p>
                                @endif
                            </td>
                            <td class="text-gray-600">{{ $record['phone'] ?? '' }}</td>
                            @if ($type === 'customers')
                                <td class="text-right tabular">{{ $money($record['credit_limit']) }}</td>
                                <td class="text-right tabular">{{ $record['credit_days'] }}</td>
                            @else
                                <td class="text-right tabular">{{ $record['payment_terms_days'] }}</td>
                            @endif
                            <td class="text-right tabular">{{ $money($record['opening_balance']) }}</td>
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
