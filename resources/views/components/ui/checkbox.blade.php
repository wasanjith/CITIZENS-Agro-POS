@props(['name', 'label', 'checked' => false, 'hint' => null, 'value' => '1'])

@php
    $id = $attributes->get('id', str_replace(['[', ']', '.'], '_', $name));
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $isChecked = (bool) old($key, $checked);
@endphp

<div {{ $attributes->only('class')->merge(['class' => 'flex items-start gap-3']) }}>
    {{-- Hidden input so an unticked box still submits a value. --}}
    <input type="hidden" name="{{ $name }}" value="0">
    <input
        type="checkbox"
        name="{{ $name }}"
        id="{{ $id }}"
        value="{{ $value }}"
        @checked($isChecked)
        {{ $attributes->except(['class', 'id'])->merge(['class' => 'mt-0.5 size-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500']) }}
    >
    <div class="text-sm">
        <label for="{{ $id }}" class="font-medium text-gray-700">{{ $label }}</label>
        @if ($hint)
            <p class="text-xs text-gray-500">{{ $hint }}</p>
        @endif
        <x-ui.field-error :name="$name" />
    </div>
</div>
