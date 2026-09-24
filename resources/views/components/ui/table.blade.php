{{--
    <x-ui.table>
        <x-slot:head><th>…</th></x-slot:head>
        <tr>…</tr>
    </x-ui.table>
--}}
<div {{ $attributes->merge(['class' => 'overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-gray-200']) }}>
    <table class="min-w-full divide-y divide-gray-200 text-sm">
        @isset($head)
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 [&_th]:px-4 [&_th]:py-3">
                <tr>{{ $head }}</tr>
            </thead>
        @endisset
        <tbody class="divide-y divide-gray-100 text-gray-800 [&_td]:px-4 [&_td]:py-3">
            {{ $slot }}
        </tbody>
    </table>
</div>
