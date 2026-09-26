@extends('layouts.app')

@section('title', 'Go-live')

@section('content')
    <x-ui.page-header title="Go-live" description="Load the balances from the old books and check that the shop is ready to run on the system." />

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Readiness checklist" class="lg:col-span-2">
            <ul class="divide-y divide-gray-100">
                @foreach ($items as $item)
                    <li class="flex items-start gap-3 py-2.5">
                        @if ($item['done'] === true)
                            <span class="mt-0.5 inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-brand-100 text-brand-700" title="Done">
                                <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.6l7.3-7.3a1 1 0 0 1 1.4 0Z" clip-rule="evenodd" /></svg>
                            </span>
                            <span class="sr-only">Done:</span>
                        @elseif ($item['done'] === false)
                            <span class="mt-0.5 inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-800" title="Not done yet">
                                <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 4a1 1 0 0 1 1 1v6a1 1 0 1 1-2 0V5a1 1 0 0 1 1-1Zm0 12a1.25 1.25 0 1 0 0-2.5A1.25 1.25 0 0 0 10 16Z" /></svg>
                            </span>
                            <span class="sr-only">Not done yet:</span>
                        @else
                            <span class="mt-0.5 inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-500" title="Check by hand">
                                <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5 10a1.25 1.25 0 1 1-2.5 0A1.25 1.25 0 0 1 5 10Zm6.25 0a1.25 1.25 0 1 1-2.5 0 1.25 1.25 0 0 1 2.5 0Zm6.25 0a1.25 1.25 0 1 1-2.5 0 1.25 1.25 0 0 1 2.5 0Z" /></svg>
                            </span>
                            <span class="sr-only">Check by hand:</span>
                        @endif
                        <div class="min-w-0 flex-1">
                            @if ($item['route'])
                                <a href="{{ route($item['route']) }}" class="text-sm font-medium text-gray-900 hover:underline">{{ $item['label'] }}</a>
                            @else
                                <span class="text-sm font-medium text-gray-900">{{ $item['label'] }}</span>
                            @endif
                            <p class="text-xs text-gray-500">{{ $item['detail'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

        <div class="space-y-6">
            @foreach (['customers' => 'credit customers', 'suppliers' => 'suppliers'] as $type => $label)
                <x-ui.card :title="'Import '.$label" :description="$type === 'customers' ? 'Name, phone, credit limit and days, and what each one owes now.' : 'Name, contact, payment terms and what the shop owes each one now.'">
                    <form method="POST" action="{{ route('admin.go-live.preview', $type) }}" enctype="multipart/form-data" class="space-y-3">
                        @csrf
                        <input type="file" name="file" accept=".xlsx,.xls,.csv" required aria-label="Excel file of {{ $label }}" class="block w-full text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:font-semibold file:text-brand-700 hover:file:bg-brand-100">
                        <div class="flex flex-wrap items-center gap-3">
                            <x-ui.button type="submit" size="sm">Check file</x-ui.button>
                            <x-ui.button variant="link" :href="route('admin.go-live.template', $type)">Download template</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @endforeach
            <x-ui.field-error name="file" />
            <p class="text-xs text-gray-500">Products and opening stock are imported on Products → Import from Excel. Bank balances are entered on each bank account.</p>
        </div>
    </div>
@endsection
