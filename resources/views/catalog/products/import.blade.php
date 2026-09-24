@extends('layouts.app')

@section('title', 'Import products')

@section('content')
    <x-ui.page-header title="Import products" description="Add many products at once from an Excel file." />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="1. Download the template">
                <p class="text-sm text-gray-600">Fill in one product per row. Keep the first row (the column names) as it is.</p>
                <x-ui.button variant="secondary" class="mt-3" :href="route('catalog.products.import.template')">Download template (.xlsx)</x-ui.button>
            </x-ui.card>

            <x-ui.card title="2. Upload the file">
                <form method="POST" action="{{ route('catalog.products.import.preview') }}" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    <div>
                        <x-ui.label for="file" :required="true">Excel file</x-ui.label>
                        <input type="file" name="file" id="file" accept=".xlsx,.xls,.csv" required class="mt-1 block w-full text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-brand-700 hover:file:bg-brand-100">
                        <x-ui.field-error name="file" />
                    </div>
                    <p class="text-sm text-gray-600">Nothing is saved yet: the next page shows every row and any problems first.</p>
                    <x-ui.button type="submit">Check file</x-ui.button>
                </form>
            </x-ui.card>
        </div>

        <x-ui.card title="Columns">
            <dl class="space-y-3 text-sm">
                @foreach ($columns as $column => $description)
                    <div>
                        <dt class="font-mono text-xs font-semibold text-gray-800">{{ $column }}</dt>
                        <dd class="text-gray-600">{{ $description }}</dd>
                    </div>
                @endforeach
            </dl>
            <p class="mt-4 text-xs text-gray-500">The import only adds new products. A short code that already exists is reported as an error.</p>
        </x-ui.card>
    </div>
@endsection
