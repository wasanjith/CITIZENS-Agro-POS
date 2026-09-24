{{-- Session flash messages: ->with('success' | 'error' | 'warning' | 'status', '…') --}}
<div {{ $attributes->merge(['class' => 'space-y-3']) }}>
    @foreach (['success' => 'success', 'status' => 'success', 'warning' => 'warning', 'error' => 'error'] as $key => $type)
        @if (session()->has($key))
            <div x-data="{ show: true }" x-show="show" x-transition>
                <x-ui.alert :type="$type" class="flex items-start justify-between gap-4">
                    <span>{{ session($key) }}</span>
                    <button type="button" class="text-lg leading-none opacity-60 hover:opacity-100" @click="show = false" aria-label="Dismiss">&times;</button>
                </x-ui.alert>
            </div>
        @endif
    @endforeach
</div>
