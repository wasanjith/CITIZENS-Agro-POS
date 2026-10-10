{{-- Signed-in user card pinned to the bottom of the sidebar; the menu opens upwards. --}}
<div class="relative shrink-0 border-t border-brand-800 p-3" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
    <div x-show="open" x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-2 scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0 scale-100"
        x-transition:leave-end="opacity-0 translate-y-2 scale-95"
        class="absolute inset-x-3 bottom-full mb-2 origin-bottom overflow-hidden rounded-md bg-white py-1 text-sm text-gray-900 shadow-lg ring-1 ring-black/5">
        <a href="{{ route('account') }}" class="block px-4 py-2 transition-all duration-150 ease-out hover:bg-brand-50 hover:pl-5 hover:text-brand-700">My account</a>
        @can('hr.attendance.self')
            <a href="{{ route('hr.attendance.mine') }}" class="block px-4 py-2 transition-all duration-150 ease-out hover:bg-brand-50 hover:pl-5 hover:text-brand-700">My attendance</a>
        @endcan
        @include('hr.partials.clock-button', ['style' => 'app'])
    </div>

    <div class="flex items-center gap-2">
        <button type="button" class="group flex min-w-0 flex-1 items-center gap-3 rounded-md px-2 py-2 text-left transition-colors duration-150 hover:bg-brand-800/60" :class="open && 'bg-brand-800/60'" @click="open = !open" :aria-expanded="open" title="Account menu">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold text-white ring-2 ring-transparent transition duration-200 group-hover:scale-105 group-hover:ring-brand-400">{{ auth()->user()->initials() }}</span>
            <span class="min-w-0">
                <span class="block truncate text-sm font-medium leading-tight text-white">{{ auth()->user()->name }}</span>
                <span class="block truncate text-xs leading-tight text-brand-200/80">{{ auth()->user()->primaryRole()?->label() }}</span>
            </span>
        </button>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="rounded-md p-2 text-brand-200 hover:bg-brand-800/60 hover:text-white" title="Sign out">
                <span class="sr-only">Sign out</span>
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" /></svg>
            </button>
        </form>
    </div>
</div>
