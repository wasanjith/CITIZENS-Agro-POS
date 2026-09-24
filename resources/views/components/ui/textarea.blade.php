@props(['name', 'label' => null, 'value' => null, 'hint' => null, 'required' => false, 'rows' => 3])

@php
    $id = $attributes->get('id', str_replace(['[', ']', '.'], '_', $name));
    $key = str_replace(['[', ']'], ['.', ''], $name);
@endphp

<div {{ $attributes->only('class') }}>
    @if ($label)
        <x-ui.label :for="$id" :required="$required">{{ $label }}</x-ui.label>
    @endif

    <textarea
        name="{{ $name }}"
        id="{{ $id }}"
        rows="{{ $rows }}"
        @if ($required) required @endif
        {{ $attributes->except(['class', 'id'])->class([
            'block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500',
            'mt-1' => $label,
            'border-red-400' => $errors->has($key),
        ]) }}
    >{{ old($key, $value) }}</textarea>

    @if ($hint)
        <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
    @endif
    <x-ui.field-error :name="$name" />
</div>
