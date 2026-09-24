{{--
    GET form for list pages. Search box + optional filters in the slot.
    <x-ui.filter-bar :action="route('admin.users.index')" placeholder="Search users">
        <x-ui.select name="filter[role]" … />
    </x-ui.filter-bar>
--}}
@props(['action', 'placeholder' => 'Search…', 'search' => true])

<form method="GET" action="{{ $action }}" {{ $attributes->merge(['class' => 'flex flex-wrap items-end gap-3']) }}>
    @if ($search)
        <div class="w-full sm:w-72">
            <label for="search" class="sr-only">Search</label>
            <input
                type="search"
                name="search"
                id="search"
                value="{{ request('search') }}"
                placeholder="{{ $placeholder }}"
                x-hotkey.f2="$el.focus()"
                class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"
            >
        </div>
    @endif

    {{ $slot }}

    @foreach (['sort', 'dir'] as $keep)
        @if (request()->filled($keep))
            <input type="hidden" name="{{ $keep }}" value="{{ request($keep) }}">
        @endif
    @endforeach

    <x-ui.button type="submit" variant="secondary">Filter</x-ui.button>
    @if (request()->hasAny(['search', 'filter']))
        <x-ui.button variant="link" :href="$action">Reset</x-ui.button>
    @endif
</form>
