@extends('layouts.app')

@section('title', $supplier->name.' · Invoices')

@use('App\Domain\Purchasing\Enums\SupplierDocumentType')

@php
    $filterUrl = fn (array $change) => route('purchasing.invoices.show', array_filter([
        'supplier' => $supplier,
        'type' => $type?->value,
        'year' => $year,
        'search' => request('search'),
        ...$change,
    ], fn ($value) => $value !== null && $value !== ''));
    $canAdd = auth()->user()->can('create', \App\Domain\Purchasing\Models\SupplierDocument::class);
@endphp

@section('content')
    <div x-data="{ adding: @js($errors->any()) }">
        <a href="{{ route('purchasing.invoices.index') }}" class="mb-2 inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
            All supplier folders
        </a>

        <x-ui.page-header :title="$supplier->name" :description="collect([$supplier->contact_person, $supplier->phone])->filter()->implode(' · ') ?: null">
            @can('view', $supplier)
                <x-ui.button variant="secondary" :href="route('purchasing.suppliers.show', $supplier)">Supplier page</x-ui.button>
            @endcan
            @if ($canAdd)
                <x-ui.button x-on:click="adding = ! adding; if (adding) $nextTick(() => $refs.addPanel.scrollIntoView({ behavior: 'smooth', block: 'start' }))" ::aria-expanded="adding.toString()">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Add document
                </x-ui.button>
            @endif
        </x-ui.page-header>

        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-ui.stat-tile label="Papers in this file" :value="number_format($total)" />
            <x-ui.stat-tile label="Balance owed" :value="'Rs. '.number_format((float) (string) $balance, 2)" :hint="$supplier->payment_terms_days ? 'Terms: '.$supplier->payment_terms_days.' days' : 'Terms: cash'" />
        </div>

        @if ($canAdd)
            <div x-ref="addPanel" x-show="adding" x-cloak x-transition.opacity class="mb-6 scroll-mt-4">
                <x-ui.card title="Add a document" description="Take a photo of each page, or choose photos / PDF files. Pages added together are kept as one document.">
                    <form method="POST" action="{{ route('purchasing.invoices.store', $supplier) }}" enctype="multipart/form-data" class="space-y-5">
                        @csrf

                        <fieldset>
                            <legend class="text-sm font-medium text-gray-700">What is it? <span class="text-red-600">*</span></legend>
                            <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                                @foreach (SupplierDocumentType::cases() as $option)
                                    <label class="relative cursor-pointer">
                                        <input type="radio" name="type" value="{{ $option->value }}" class="peer sr-only" @checked(old('type', SupplierDocumentType::Invoice->value) === $option->value)>
                                        <span class="flex min-h-12 items-center justify-center rounded-md bg-white px-3 py-2 text-center text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 transition hover:bg-gray-50 peer-checked:bg-brand-50 peer-checked:text-brand-800 peer-checked:ring-2 peer-checked:ring-brand-600 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand-600">
                                            {{ $option->label() }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <x-ui.field-error name="type" />
                        </fieldset>

                        <div class="grid gap-4 sm:grid-cols-3">
                            <x-ui.date-input name="document_date" label="Date on the document" :value="now()->format('Y-m-d')" :max="now()->format('Y-m-d')" required />
                            <x-ui.input name="reference" label="Number" hint="Invoice no., slip no. or receipt no." maxlength="60" autocomplete="off" />
                            <x-ui.money-input name="amount" label="Amount" />
                        </div>

                        <x-ui.textarea name="note" label="Note" rows="2" maxlength="500" placeholder="e.g. cheque 004512 for the October delivery" />

                        <div>
                            <p class="mb-2 text-sm font-medium text-gray-700">Pages <span class="text-red-600">*</span></p>
                            @error('document_files')
                                <x-ui.alert type="error" class="mb-3">{{ $message }}</x-ui.alert>
                            @enderror
                            @if ($errors->has('document_files.*'))
                                <x-ui.alert type="error" class="mb-3">{{ $errors->first('document_files.*') }}</x-ui.alert>
                            @endif
                            <x-purchasing.invoice-photos-input name="document_files">
                                <x-ui.button type="submit" class="w-full sm:w-auto">Save document</x-ui.button>
                            </x-purchasing.invoice-photos-input>
                        </div>
                    </form>
                </x-ui.card>
            </div>
        @endif

        {{-- Type chips --}}
        <nav class="mb-3 flex gap-2 overflow-x-auto pb-1" aria-label="Document type">
            @php $chips = ['' => ['All', $total]] + collect(SupplierDocumentType::cases())->mapWithKeys(fn ($t) => [$t->value => [$t->pluralLabel(), $counts[$t->value]]])->all(); @endphp
            @foreach ($chips as $value => [$label, $count])
                @php $active = ($type?->value ?? '') === $value; @endphp
                <a href="{{ $filterUrl(['type' => $value, 'page' => null]) }}"
                   @class([
                       'inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium ring-1 ring-inset transition',
                       'bg-brand-600 text-white ring-brand-600' => $active,
                       'bg-white text-gray-700 ring-gray-300 hover:bg-gray-50' => ! $active,
                   ])
                   @if ($active) aria-current="true" @endif>
                    {{ $label }}
                    <span @class(['rounded-full px-1.5 text-xs tabular', 'bg-white/20' => $active, 'bg-gray-100 text-gray-600' => ! $active])>{{ $count }}</span>
                </a>
            @endforeach
        </nav>

        <form method="GET" action="{{ route('purchasing.invoices.show', $supplier) }}" class="mb-5 flex flex-wrap items-end gap-3">
            @if ($type)
                <input type="hidden" name="type" value="{{ $type->value }}">
            @endif
            <div class="w-full sm:w-72">
                <label for="search" class="sr-only">Search</label>
                <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Number, amount, note or GRN"
                       x-hotkey.f2="$el.focus()"
                       class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            </div>
            @if (count($years) > 1)
                <x-ui.select name="year" :options="collect($years)->mapWithKeys(fn ($y) => [$y => $y])->all()" :value="$year" placeholder="All years" />
            @endif
            <x-ui.button type="submit" variant="secondary">Filter</x-ui.button>
            @if (request()->hasAny(['search', 'year', 'type']))
                <x-ui.button variant="link" :href="route('purchasing.invoices.show', $supplier)">Reset</x-ui.button>
            @endif
        </form>

        @if ($items->isEmpty())
            <x-ui.empty-state
                :title="$total === 0 ? 'This folder is empty' : 'Nothing matches'"
                :description="$total === 0 ? 'Add the supplier\'s invoices, bank deposit slips and receipts. Invoice photos taken on goods receipts show here too.' : 'Try another type, year or search.'">
                @if ($total === 0 && $canAdd)
                    <x-ui.button x-on:click="adding = true; $nextTick(() => $refs.addPanel.scrollIntoView({ behavior: 'smooth', block: 'start' }))">Add document</x-ui.button>
                @endif
            </x-ui.empty-state>
        @else
            <ul class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-6">
                @foreach ($items as $item)
                    @php
                        $first = $item->files[0] ?? null;
                        $pages = count($item->files);
                        $document = $item->document;
                        $receipt = $item->receipt;
                    @endphp
                    <li class="flex flex-col overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200 transition hover:shadow-md">
                        <div class="relative">
                            @if ($first)
                                <a href="{{ $first['url'] }}" target="_blank" rel="noopener" class="block bg-gray-100" title="Open {{ $first['name'] }}">
                                    @if ($first['image'])
                                        <img src="{{ $first['url'] }}" alt="{{ $item->type->label() }} {{ $item->reference }}" loading="lazy" class="aspect-[4/3] w-full object-cover object-top">
                                    @else
                                        <span class="flex aspect-[4/3] w-full flex-col items-center justify-center gap-1 p-3 text-center">
                                            <span class="rounded bg-red-600 px-2 py-0.5 text-xs font-bold text-white">PDF</span>
                                            <span class="line-clamp-2 break-all text-xs text-gray-600">{{ $first['name'] }}</span>
                                        </span>
                                    @endif
                                </a>
                            @endif
                            <x-ui.badge :color="$item->type->color()" class="absolute left-2 top-2 shadow-sm">{{ $item->type->label() }}</x-ui.badge>
                            @if ($pages > 1)
                                <span class="absolute bottom-2 right-2 rounded bg-gray-900/70 px-1.5 py-0.5 text-[11px] font-medium text-white">{{ $pages }} pages</span>
                            @endif

                            @if ($document)
                                @can('delete', $document)
                                    <button type="button" x-on:click="$dispatch('open-modal', 'remove-document-{{ $document->id }}')"
                                            class="absolute right-1 top-1 flex size-8 items-center justify-center rounded-full bg-white/90 text-lg leading-none text-gray-700 shadow hover:bg-red-50 hover:text-red-700"
                                            aria-label="Remove this {{ Str::lower($item->type->label()) }}">&times;</button>
                                @endcan
                            @endif
                        </div>

                        <div class="flex flex-1 flex-col gap-1 p-3 text-sm">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-2">
                                <span class="whitespace-nowrap font-semibold text-gray-900 tabular">{{ $item->date->format('Y-m-d') }}</span>
                                @if ($item->amount !== null)
                                    <span class="whitespace-nowrap text-gray-700 tabular">Rs. {{ number_format((float) $item->amount, 2) }}</span>
                                @endif
                            </div>

                            @if ($item->reference)
                                <p class="truncate font-mono text-xs text-gray-700" title="{{ $item->reference }}">No. {{ $item->reference }}</p>
                            @endif

                            @if ($receipt)
                                <p class="flex flex-wrap items-center gap-1 text-xs text-gray-500">
                                    From
                                    <a href="{{ route('purchasing.goods-receipts.show', $receipt) }}" class="font-mono text-brand-700 hover:underline">{{ $receipt->number }}</a>
                                    @if ($receipt->status !== \App\Domain\Purchasing\Enums\GoodsReceiptStatus::Posted)
                                        <x-ui.badge :color="$receipt->status->color()">{{ $receipt->status->label() }}</x-ui.badge>
                                    @endif
                                </p>
                            @endif

                            @if ($item->note)
                                <p class="line-clamp-2 text-xs text-gray-600" title="{{ $item->note }}">{{ $item->note }}</p>
                            @endif

                            @if ($pages > 1)
                                <p class="flex flex-wrap items-center gap-1 text-xs text-gray-500">
                                    Pages
                                    @foreach ($item->files as $file)
                                        <a href="{{ $file['url'] }}" target="_blank" rel="noopener" class="inline-flex size-6 items-center justify-center rounded bg-gray-100 font-medium text-gray-700 hover:bg-brand-50 hover:text-brand-700" title="{{ $file['name'] }}">{{ $loop->iteration }}</a>
                                    @endforeach
                                </p>
                            @endif

                            <p class="mt-auto pt-1 text-[11px] text-gray-400">
                                Added {{ $item->added?->format('Y-m-d') ?? '—' }}@if ($item->by) · {{ $item->by }}@endif
                            </p>
                        </div>

                        @if ($document)
                            @can('delete', $document)
                                <x-ui.confirm-modal name="remove-document-{{ $document->id }}" :action="route('purchasing.invoices.destroy', [$supplier, $document])" method="DELETE" title="Remove this {{ Str::lower($item->type->label()) }}?" confirm="Remove">
                                    {{ $pages === 1 ? 'Its photo is' : "All {$pages} pages are" }} deleted from {{ $supplier->name }}'s folder. The removal is kept in the audit log.
                                </x-ui.confirm-modal>
                            @endcan
                        @endif
                    </li>
                @endforeach
            </ul>

            <x-ui.pagination :paginator="$items" />
        @endif
    </div>
@endsection
