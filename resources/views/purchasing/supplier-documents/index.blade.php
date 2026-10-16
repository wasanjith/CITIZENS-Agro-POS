@extends('layouts.app')

@section('title', 'Invoices')

@section('content')
    <x-ui.page-header title="Invoices" description="A file for each supplier: invoices, bank deposit slips and receipts. Open a folder to view or add papers." />

    @php
        // Lower-cased name, contact and phone per folder, for the instant search box.
        $haystacks = $folders->map(fn (array $folder) => mb_strtolower(implode(' ', array_filter([
            $folder['supplier']->name, $folder['supplier']->contact_person, $folder['supplier']->phone,
        ]))))->values();
    @endphp

    <div x-data="{
        q: '',
        hay: @js($haystacks),
        get needle() { return this.q.trim().toLowerCase() },
        shows(i) { return ! this.needle || this.hay[i].includes(this.needle) },
        get none() { return this.needle !== '' && ! this.hay.some((h) => h.includes(this.needle)) },
    }">
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <div class="relative w-full sm:w-80">
                <label for="folder-search" class="sr-only">Search suppliers</label>
                <svg class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                <input type="search" id="folder-search" x-model="q" placeholder="Search supplier, contact or phone" autocomplete="off"
                       x-hotkey.f2="$el.focus()"
                       class="block w-full rounded-md border-gray-300 pl-10 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            </div>

            <div class="flex rounded-md shadow-sm ring-1 ring-inset ring-gray-300" role="group" aria-label="Sort folders">
                @foreach (['name' => 'A–Z', 'recent' => 'Recently updated'] as $key => $label)
                    <a href="{{ route('purchasing.invoices.index', $key === 'name' ? [] : ['sort' => $key]) }}"
                       @class([
                           'px-3 py-2 text-sm font-medium first:rounded-l-md last:rounded-r-md',
                           'bg-brand-600 text-white' => $sort === $key,
                           'bg-white text-gray-700 hover:bg-gray-50' => $sort !== $key,
                       ])
                       @if ($sort === $key) aria-current="true" @endif>{{ $label }}</a>
                @endforeach
            </div>

            <p class="text-sm text-gray-500 sm:ml-auto">{{ $folders->count() }} {{ Str::plural('supplier', $folders->count()) }} · {{ number_format($folders->sum('total')) }} {{ Str::plural('paper', $folders->sum('total')) }}</p>
        </div>

        @if ($folders->isEmpty())
            <x-ui.empty-state title="No suppliers yet" description="Add a supplier first; each one gets its own folder here.">
                @can('create', \App\Domain\Purchasing\Models\Supplier::class)
                    <x-ui.button :href="route('purchasing.suppliers.create')">Add supplier</x-ui.button>
                @endcan
            </x-ui.empty-state>
        @else
            <ul class="grid grid-cols-2 gap-x-4 gap-y-5 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-5">
                @foreach ($folders as $folder)
                    @php
                        $supplier = $folder['supplier'];
                        $empty = $folder['total'] === 0;
                    @endphp
                    <li x-show="shows({{ $loop->index }})" class="flex">
                        <a href="{{ route('purchasing.invoices.show', $supplier) }}"
                           class="group relative flex w-full flex-col pt-3 focus:outline-none"
                           title="Open {{ $supplier->name }}'s file">
                            {{-- Folder tab --}}
                            <span @class([
                                'absolute left-0 top-0 h-5 w-[45%] rounded-t-lg transition',
                                'bg-amber-300 group-hover:bg-amber-400' => ! $empty,
                                'bg-gray-200 group-hover:bg-gray-300' => $empty,
                            ]) aria-hidden="true"></span>

                            <span @class([
                                'relative flex min-h-44 flex-1 flex-col sm:aspect-square rounded-lg rounded-tl-none p-3 shadow-sm ring-1 transition duration-150 group-hover:-translate-y-0.5 group-hover:shadow-md group-focus-visible:ring-2 group-focus-visible:ring-brand-600 sm:p-4',
                                'bg-amber-100 ring-amber-300' => ! $empty,
                                'bg-gray-50 ring-gray-200' => $empty,
                            ])>
                                <span class="flex items-start justify-between gap-2">
                                    <svg @class(['size-7 shrink-0 sm:size-8', 'text-amber-500' => ! $empty, 'text-gray-300' => $empty]) viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19.5 21a3 3 0 0 0 3-3v-4.5a3 3 0 0 0-3-3h-15a3 3 0 0 0-3 3V18a3 3 0 0 0 3 3h15ZM1.5 10.146V6a3 3 0 0 1 3-3h5.379a2.25 2.25 0 0 1 1.59.659l2.122 2.121c.14.141.331.22.53.22H19.5a3 3 0 0 1 3 3v1.146A4.483 4.483 0 0 0 19.5 9h-15a4.483 4.483 0 0 0-3 1.146Z" /></svg>
                                    <span @class([
                                        'rounded-full px-2 py-0.5 text-xs font-semibold tabular',
                                        'bg-white/80 text-amber-900' => ! $empty,
                                        'bg-white text-gray-400' => $empty,
                                    ])>{{ $folder['total'] }}</span>
                                </span>

                                <span class="mt-2 line-clamp-2 break-words text-sm font-semibold leading-snug text-gray-900 sm:text-base">{{ $supplier->name }}</span>
                                @if ($supplier->contact_person || $supplier->phone)
                                    <span class="mt-0.5 truncate text-xs text-gray-600">{{ $supplier->contact_person ?: $supplier->phone }}</span>
                                @endif

                                <span class="mt-auto">
                                    @if ($empty)
                                        <span class="text-xs text-gray-400">Empty folder</span>
                                    @else
                                        <span class="flex flex-wrap gap-1">
                                            @foreach (\App\Domain\Purchasing\Enums\SupplierDocumentType::cases() as $type)
                                                @if ($folder['counts'][$type->value] > 0)
                                                    <span class="rounded bg-white/70 px-1.5 py-0.5 text-[11px] font-medium text-gray-700">{{ $type->countLabel($folder['counts'][$type->value]) }}</span>
                                                @endif
                                            @endforeach
                                        </span>
                                        @if ($folder['last'])
                                            <span class="mt-1.5 block text-[11px] text-amber-900/70">Updated {{ $folder['last']->format('Y-m-d') }}</span>
                                        @endif
                                    @endif
                                    @unless ($supplier->is_active)
                                        <span class="mt-1 block text-[11px] font-medium text-red-700">Inactive supplier</span>
                                    @endunless
                                </span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>

            <p x-show="none" x-cloak class="mt-6 rounded-lg border-2 border-dashed border-gray-200 px-6 py-10 text-center text-sm text-gray-500">
                No supplier matches “<span x-text="q"></span>”.
            </p>
        @endif
    </div>
@endsection
