{{-- Full-screen POS layout (counter, cashier, drawer and handover screens). No sidebar; keyboard first. --}}
@php
    $posTerminal = app(\App\Domain\Identity\Support\CurrentTerminal::class)->get();
    $posUser = auth()->user();
    $posHolder = app(\App\Domain\Identity\Services\CashierAuthority::class)->holder();
    $navItems = array_filter([
        $posUser?->can('pos.sell') && $posTerminal ? ['label' => 'Billing', 'route' => 'pos.counter', 'active' => 'pos.counter'] : null,
        $posTerminal?->isMainCashier() && $posUser?->canAny(['pos.settle', 'drawer.manage', 'drawer.handover']) ? ['label' => 'Cashier', 'route' => 'pos.cashier', 'active' => 'pos.cashier'] : null,
        $posTerminal?->isMainCashier() ? ['label' => 'Drawer', 'route' => 'pos.drawer.show', 'active' => 'pos.drawer.*'] : null,
        ['label' => 'Back office', 'route' => 'dashboard', 'active' => 'dashboard'],
    ]);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-gray-100">
<head>
    @include('layouts.partials.head')
</head>
<body class="h-full overflow-hidden font-sans text-gray-900 antialiased">
    <div class="flex h-full flex-col">
        <header class="flex h-12 shrink-0 items-center justify-between gap-3 bg-brand-900 px-3 text-sm text-white sm:px-4">
            <div class="flex min-w-0 items-center gap-3">
                <span class="hidden font-bold sm:inline">CITIZENS Agro</span>
                @if ($posTerminal)
                    <span class="rounded bg-white/15 px-2 py-0.5 text-xs font-semibold">{{ $posTerminal->displayName() }}</span>
                @endif
                <nav class="flex items-center gap-1" aria-label="POS">
                    @foreach ($navItems as $item)
                        <a href="{{ route($item['route']) }}" @class([
                            'rounded px-2 py-1 text-xs font-medium',
                            'bg-brand-700 text-white' => request()->routeIs($item['active']),
                            'text-brand-100 hover:bg-brand-800' => ! request()->routeIs($item['active']),
                        ])>{{ $item['label'] }}</a>
                    @endforeach
                </nav>
                @yield('pos-title')
            </div>
            <div class="flex items-center gap-3">
                @yield('pos-status')
                @if ($posHolder)
                    <span class="hidden text-xs text-brand-100 md:inline" title="Cashier authority">Cashier: <span class="font-semibold text-white" data-cashier-holder>{{ $posHolder->name }}</span></span>
                @endif
                <span class="font-medium">{{ $posUser?->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded bg-brand-800 px-2 py-1 text-xs hover:bg-brand-700">Sign out</button>
                </form>
            </div>
        </header>

        @if (session()->hasAny(['success', 'error', 'warning']) || $errors->has('drawer'))
            <div class="shrink-0 px-3 pt-2 sm:px-4">
                <x-ui.flash />
                @error('drawer')
                    <x-ui.alert type="error">{{ $message }}</x-ui.alert>
                @enderror
            </div>
        @endif

        <main class="min-h-0 flex-1 @yield('pos-main-class', 'overflow-y-auto')">
            @yield('content')
        </main>
    </div>

    @if ($printUrl = session('print_url'))
        {{-- A slip to print on this terminal (handover, Z report, test page). --}}
        <iframe src="{{ $printUrl }}" class="pointer-events-none fixed bottom-0 right-0 size-px opacity-0" aria-hidden="true" tabindex="-1"></iframe>
    @endif

    @stack('scripts')
</body>
</html>
