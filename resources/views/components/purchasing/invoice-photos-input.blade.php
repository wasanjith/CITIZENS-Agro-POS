{{--
    Supplier invoice photo picker. "Take photo" opens the phone's back camera; "Choose files"
    opens the gallery or file picker (photos or PDF). Photos are shrunk in the browser before
    upload. Submits as {{ $name }}[] with the surrounding form (which needs enctype="multipart/form-data").

    <x-purchasing.invoice-photos-input name="invoice_files" />

    The slot (e.g. a submit button) is shown once at least one photo is picked.
--}}
@props(['name' => 'invoice_files', 'max' => \App\Domain\Purchasing\Models\GoodsReceiptInvoice::MAX_FILES])

@php $id = 'invoice-photos-'.\Illuminate\Support\Str::random(6); @endphp

<div x-data="invoicePhotos({ max: {{ (int) $max }}, maxBytes: {{ \App\Domain\Purchasing\Models\GoodsReceiptInvoice::MAX_KB * 1024 }} })" {{ $attributes }}>
    <input type="file" name="{{ $name }}[]" multiple x-ref="output" class="hidden" tabindex="-1" aria-hidden="true">
    <input type="file" id="{{ $id }}-camera" accept="image/*" capture="environment" class="sr-only" x-on:change="add($event)" :disabled="busy || ! canAddMore">
    <input type="file" id="{{ $id }}-files" accept="image/*,application/pdf" multiple class="sr-only" x-on:change="add($event)" :disabled="busy || ! canAddMore">

    <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
        <label for="{{ $id }}-camera"
               class="inline-flex min-h-12 cursor-pointer items-center justify-center gap-2 rounded-md bg-brand-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-brand-700 focus-within:outline-2 sm:min-h-0 sm:py-2"
               :class="{ 'pointer-events-none opacity-50': busy || ! canAddMore }">
            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z" /></svg>
            Take photo
        </label>
        <label for="{{ $id }}-files"
               class="inline-flex min-h-12 cursor-pointer items-center justify-center gap-2 rounded-md bg-white px-4 py-3 text-sm font-semibold text-gray-800 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:min-h-0 sm:py-2"
               :class="{ 'pointer-events-none opacity-50': busy || ! canAddMore }">
            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" /></svg>
            Choose files
        </label>
    </div>

    <p class="mt-2 text-xs text-gray-500">Photos or PDF, up to {{ (int) $max }} at a time. Take one photo per invoice page.</p>
    <p x-show="busy" x-cloak class="mt-2 text-sm text-gray-600">Preparing photo…</p>
    <p x-show="error" x-text="error" x-cloak class="mt-2 text-sm text-red-700" role="alert"></p>

    <ul x-show="items.length" x-cloak class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-6">
        <template x-for="(item, index) in items" :key="item.key">
            <li class="relative overflow-hidden rounded-md bg-gray-100 ring-1 ring-gray-200">
                <template x-if="item.isImage">
                    <img :src="item.preview" :alt="item.name" class="aspect-[3/4] w-full object-cover">
                </template>
                <template x-if="! item.isImage">
                    <div class="flex aspect-[3/4] w-full flex-col items-center justify-center gap-1 p-2 text-center">
                        <span class="rounded bg-red-600 px-1.5 py-0.5 text-xs font-bold text-white">PDF</span>
                        <span class="line-clamp-2 break-all text-xs text-gray-600" x-text="item.name"></span>
                    </div>
                </template>
                <span class="absolute bottom-0 left-0 right-0 bg-gray-900/60 px-1.5 py-0.5 text-[11px] text-white tabular" x-text="sizeText(item.size)"></span>
                <button type="button" x-on:click="remove(index)" class="absolute right-1 top-1 flex size-8 items-center justify-center rounded-full bg-white/90 text-lg leading-none text-gray-700 shadow hover:bg-red-50 hover:text-red-700" aria-label="Remove this photo">&times;</button>
            </li>
        </template>
    </ul>

    @if (! $slot->isEmpty())
        <div x-show="items.length" x-cloak class="mt-3">{{ $slot }}</div>
    @endif
</div>
