{{-- From / to filter for books and reports. $action, $from, $to; --}}
<form method="GET" action="{{ $action }}" class="mb-4 flex flex-wrap items-end gap-3 print:hidden">
    <x-ui.date-input name="from" label="From" :value="$from->toDateString()" />
    <x-ui.date-input name="to" label="To" :value="$to->toDateString()" />
    <x-ui.button type="submit" variant="secondary">Show</x-ui.button>
</form>
