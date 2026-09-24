<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-brand-900">
<head>
    @include('layouts.partials.head')
</head>
<body class="flex min-h-full items-center justify-center px-4 py-10 font-sans text-gray-900 antialiased">
    <div class="w-full @yield('width', 'max-w-sm')">
        <div class="mb-6 text-center text-white">
            <p class="text-2xl font-bold tracking-tight">CITIZENS Agro</p>
            <p class="font-sinhala text-sm text-brand-200">සිටිසන්ස් ඇග්‍රෝ · POS</p>
        </div>
        <div class="rounded-xl bg-white p-6 shadow-xl sm:p-8">
            @yield('content')
        </div>
        @hasSection('below')
            <div class="mt-4 text-center text-sm text-brand-100">@yield('below')</div>
        @endif
    </div>
    @stack('scripts')
</body>
</html>
