{{-- Full-screen POS layout (counter and cashier screens, Phase 3). No sidebar; keyboard first. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-gray-100">
<head>
    @include('layouts.partials.head')
</head>
<body class="h-full overflow-hidden font-sans text-gray-900 antialiased">
    <div class="flex h-full flex-col">
        <header class="flex h-12 shrink-0 items-center justify-between bg-brand-900 px-4 text-sm text-white">
            <div class="flex items-center gap-3">
                <span class="font-bold">CITIZENS Agro</span>
                @yield('pos-title')
            </div>
            <div class="flex items-center gap-4">
                @yield('pos-status')
                <span>{{ auth()->user()?->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded bg-brand-800 px-2 py-1 text-xs hover:bg-brand-700">Sign out</button>
                </form>
            </div>
        </header>
        <main class="min-h-0 flex-1">
            @yield('content')
        </main>
    </div>
    @stack('scripts')
</body>
</html>
