{{--
    Cash count by denomination with a live total.
    <x-pos.denomination-count :denominations="$denominations" :count="$count" total-model="counted" />
    Posts denominations[5000], …, denominations[coins]. The total is exposed to the parent
    Alpine scope through $dispatch('count-total', total).
--}}
@props(['denominations', 'count' => [], 'title' => 'Count the drawer'])

@php
    $count = old('denominations', $count ?? []);
@endphp

<div
    x-data="{
        notes: @js(collect($denominations)->mapWithKeys(fn ($note) => [$note => (int) ($count[$note] ?? 0) ?: ''])->all()),
        coins: @js(($count['coins'] ?? '') ?: ''),
        get total() {
            return Object.entries(this.notes).reduce((sum, [note, pieces]) => sum + Number(note) * Number(pieces || 0), 0) + Number(this.coins || 0);
        },
    }"
    x-effect="$dispatch('count-total', total)"
    {{ $attributes->merge(['class' => 'rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200']) }}
>
    <div class="flex items-baseline justify-between gap-3">
        <h3 class="font-semibold text-gray-900">{{ $title }}</h3>
        <p class="text-right">
            <span class="block text-xs uppercase tracking-wide text-gray-500">Counted</span>
            <span class="text-2xl font-bold tabular" x-text="'Rs. ' + total.toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></span>
        </p>
    </div>

    <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
        @foreach ($denominations as $note)
            <label class="flex items-center gap-2 rounded-md bg-gray-50 px-2 py-1.5 ring-1 ring-gray-200">
                <span class="w-14 text-right text-sm font-semibold tabular">{{ number_format((int) $note) }}</span>
                <span class="text-gray-400">×</span>
                <input type="number" min="0" step="1" name="denominations[{{ $note }}]" x-model="notes['{{ $note }}']" class="w-full rounded border-gray-300 px-2 py-1 text-right text-sm tabular" inputmode="numeric" @focus="$el.select()">
            </label>
        @endforeach
        <label class="col-span-2 flex items-center gap-2 rounded-md bg-gray-50 px-2 py-1.5 ring-1 ring-gray-200 sm:col-span-1">
            <span class="w-14 text-right text-sm font-semibold">Coins Rs.</span>
            <input type="text" inputmode="decimal" name="denominations[coins]" x-model="coins" class="w-full rounded border-gray-300 px-2 py-1 text-right text-sm tabular" placeholder="0.00" @focus="$el.select()">
        </label>
    </div>

    @if ($errors->has('denominations') || $errors->has('denominations.*'))
        <p class="mt-2 text-sm text-red-600">Enter whole numbers of notes and the coin amount in rupees.</p>
    @endif
</div>
