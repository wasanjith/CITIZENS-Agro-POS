{{--
    <x-ui.modal name="confirm-delete" title="Delete?"> … </x-ui.modal>
    Open with: $dispatch('open-modal', 'confirm-delete'); close with $dispatch('close-modal', 'confirm-delete')
--}}
@props(['name', 'title' => null, 'maxWidth' => 'lg', 'show' => false])

@php
    $widths = ['sm' => 'sm:max-w-sm', 'md' => 'sm:max-w-md', 'lg' => 'sm:max-w-lg', 'xl' => 'sm:max-w-xl', '2xl' => 'sm:max-w-2xl'];
@endphp

<div
    x-data="{ show: @js($show) }"
    x-on:open-modal.window="if ($event.detail === @js($name)) show = true"
    x-on:close-modal.window="if ($event.detail === @js($name)) show = false"
    x-on:keydown.escape.window="show = false"
    x-show="show"
    x-cloak
    class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0"
    role="dialog"
    aria-modal="true"
>
    <div x-show="show" x-transition.opacity class="fixed inset-0 bg-gray-900/50" @click="show = false"></div>

    <div
        x-show="show"
        x-transition
        x-trap.inert.noscroll="show"
        class="relative mx-auto mt-16 w-full {{ $widths[$maxWidth] ?? $widths['lg'] }} overflow-hidden rounded-lg bg-white shadow-xl"
    >
        @if ($title)
            <div class="border-b border-gray-200 px-5 py-3">
                <h2 class="text-base font-semibold text-gray-900">{{ $title }}</h2>
            </div>
        @endif
        <div class="px-5 py-4">
            {{ $slot }}
        </div>
        @isset($footer)
            <div class="flex justify-end gap-2 border-t border-gray-200 bg-gray-50 px-5 py-3">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
