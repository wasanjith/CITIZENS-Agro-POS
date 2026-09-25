<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-gray-50">
<head>
    @include('layouts.partials.head')
</head>
<body class="h-full font-sans text-gray-900 antialiased" x-data="{ sidebarOpen: false }">
    {{-- Mobile sidebar --}}
    <div x-show="sidebarOpen" x-cloak class="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true">
        <div class="fixed inset-0 bg-gray-900/60" @click="sidebarOpen = false"></div>
        <aside class="relative flex h-full w-64 flex-col bg-brand-900" x-trap.noscroll="sidebarOpen">
            <div class="flex h-16 shrink-0 items-center justify-between px-4">
                <span class="text-lg font-bold text-white">CITIZENS Agro</span>
                <button type="button" class="text-2xl text-brand-100" @click="sidebarOpen = false" aria-label="Close menu">&times;</button>
            </div>
            @include('layouts.partials.sidebar')
        </aside>
    </div>

    {{-- Desktop sidebar --}}
    <aside class="hidden lg:fixed lg:inset-y-0 lg:flex lg:w-60 lg:flex-col bg-brand-900">
        <div class="flex h-16 shrink-0 items-center px-6">
            <a href="{{ route('dashboard') }}" class="text-lg font-bold tracking-tight text-white">CITIZENS Agro</a>
        </div>
        @include('layouts.partials.sidebar')
        <p class="shrink-0 px-6 py-4 text-xs text-brand-200/70">POS · v0.4 (Phase 3)</p>
    </aside>

    <div class="lg:pl-60">
        <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-gray-200 bg-white/95 px-4 backdrop-blur sm:px-6">
            <button type="button" class="-ml-1 rounded p-2 text-gray-600 lg:hidden" @click="sidebarOpen = true" aria-label="Open menu">
                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
            </button>

            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2 text-xs">
                @if ($currentTerminal)
                    <x-ui.badge :color="$currentTerminal->isMainCashier() ? 'purple' : 'blue'" title="This device">
                        {{ $currentTerminal->displayName() }}
                    </x-ui.badge>
                @else
                    <x-ui.badge color="gray" title="This browser is not registered as a terminal">Unregistered device</x-ui.badge>
                @endif

                @if ($cashierHolder)
                    <span class="hidden truncate text-gray-500 sm:inline">
                        Cashier: <span class="font-semibold text-gray-800">{{ $cashierHolder->name }}</span>
                    </span>
                @endif
            </div>

            @can('catalog.view')
                <div
                    class="relative hidden w-72 md:block"
                    x-data="productSearch({ url: @js(route('api.pos.search')) })"
                    @click.outside="open = false"
                >
                    <label for="global-product-search" class="sr-only">Search products</label>
                    <input
                        type="search"
                        id="global-product-search"
                        x-model="query"
                        x-hotkey.ctrl.k="$el.focus(); $el.select()"
                        @input.debounce.150ms="search()"
                        @focus="items.length && (open = true)"
                        @keydown.arrow-down.prevent="move(1)"
                        @keydown.arrow-up.prevent="move(-1)"
                        @keydown.enter.prevent="go()"
                        @keydown.escape="open = false"
                        placeholder="Search products (Ctrl+K)"
                        autocomplete="off"
                        class="block w-full rounded-md border-gray-300 bg-gray-50 text-sm focus:border-brand-500 focus:bg-white focus:ring-brand-500"
                    >
                    <ul x-show="open" x-cloak class="absolute right-0 z-40 mt-1 max-h-96 w-96 overflow-auto rounded-md bg-white py-1 text-sm shadow-lg ring-1 ring-black/5">
                        <template x-for="(item, index) in items" :key="item.key">
                            <li
                                @click="go(item)"
                                @mouseenter="highlighted = index"
                                :class="index === highlighted ? 'bg-brand-50' : ''"
                                class="flex cursor-pointer items-start justify-between gap-3 px-3 py-2"
                            >
                                <span class="min-w-0">
                                    <span class="font-mono text-xs font-semibold text-brand-700" x-text="item.short_code"></span>
                                    <span class="font-medium text-gray-900" x-text="item.name"></span>
                                    <span class="block truncate font-sinhala text-xs text-gray-500" x-text="item.name_si"></span>
                                </span>
                                <span class="whitespace-nowrap text-xs tabular text-gray-600" x-text="price(item)"></span>
                            </li>
                        </template>
                        <li x-show="items.length === 0" class="px-3 py-2 text-gray-500">No products found.</li>
                    </ul>
                </div>
            @endcan

            @php $unreadCount = auth()->user()->unreadNotifications()->count(); @endphp
            <a href="{{ route('notifications.index') }}" class="relative rounded-full p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-700" title="Notifications">
                <span class="sr-only">Notifications{{ $unreadCount ? " ({$unreadCount} unread)" : '' }}</span>
                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
                @if ($unreadCount)
                    <span class="absolute -right-0.5 -top-0.5 flex min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-xs font-semibold text-white">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
                @endif
            </a>

            <div class="flex items-center gap-3" x-data="{ open: false }" @click.outside="open = false">
                <button type="button" class="flex items-center gap-2 rounded-full p-0.5 text-sm" @click="open = !open" :aria-expanded="open">
                    <span class="flex size-8 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold text-white">{{ auth()->user()->initials() }}</span>
                    <span class="hidden text-left sm:block">
                        <span class="block font-medium leading-tight">{{ auth()->user()->name }}</span>
                        <span class="block text-xs leading-tight text-gray-500">{{ auth()->user()->primaryRole()?->label() }}</span>
                    </span>
                </button>
                <div x-show="open" x-cloak x-transition class="absolute right-4 top-14 w-48 rounded-md bg-white py-1 text-sm shadow-lg ring-1 ring-black/5">
                    <a href="{{ route('account') }}" class="block px-4 py-2 hover:bg-gray-50">My account</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="block w-full px-4 py-2 text-left hover:bg-gray-50">Sign out</button>
                    </form>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <x-ui.flash class="mb-6" />
            @if (session('print_url'))
                {{-- A receipt to print on this PC's printer (the page prints itself, Chrome --kiosk-printing). --}}
                <iframe src="{{ session('print_url') }}" title="Printing" aria-hidden="true" class="pointer-events-none fixed bottom-0 right-0 size-px border-0 opacity-0"></iframe>
            @endif
            @yield('content')
        </main>
    </div>

    @stack('scripts')
</body>
</html>
