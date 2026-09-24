{{--
    A modal that submits a form after confirmation.
    <x-ui.confirm-modal name="unregister-1" :action="route(...)" method="DELETE" title="Unregister?" confirm="Unregister">Text</x-ui.confirm-modal>
--}}
@props(['name', 'action', 'method' => 'POST', 'title' => 'Are you sure?', 'confirm' => 'Confirm', 'variant' => 'danger'])

<x-ui.modal :name="$name" :title="$title" max-width="md">
    <div class="text-sm text-gray-600">{{ $slot }}</div>

    <x-slot:footer>
        <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', @js($name))">Cancel</x-ui.button>
        <form method="POST" action="{{ $action }}">
            @csrf
            @if (strtoupper($method) !== 'POST')
                @method($method)
            @endif
            <x-ui.button type="submit" :variant="$variant">{{ $confirm }}</x-ui.button>
        </form>
    </x-slot:footer>
</x-ui.modal>
