@props([
    'name',
    'label' => null,
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'required' => false,
    'prefix' => null,
    'bag' => 'default',
])

@php
    $id = $attributes->get('id', str_replace(['[', ']', '.'], '_', $name));
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $hasError = $errors->getBag($bag)->has($key);
    $inputValue = $type === 'password' ? null : old($key, $value);
@endphp

<div {{ $attributes->only('class') }}>
    @if ($label)
        <x-ui.label :for="$id" :required="$required">{{ $label }}</x-ui.label>
    @endif

    <div @class(['mt-1' => $label, 'flex rounded-md shadow-sm' => $prefix])>
        @if ($prefix)
            <span class="inline-flex items-center rounded-l-md border border-r-0 border-gray-300 bg-gray-50 px-3 text-sm text-gray-500">{{ $prefix }}</span>
        @endif
        <input
            type="{{ $type }}"
            name="{{ $name }}"
            id="{{ $id }}"
            @if (! is_null($inputValue)) value="{{ $inputValue }}" @endif
            @if ($required) required @endif
            {{ $attributes->except(['class', 'id'])->class([
                'block w-full border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500',
                'rounded-md shadow-sm' => ! $prefix,
                'rounded-r-md' => $prefix,
                'border-red-400 text-red-900 focus:border-red-500 focus:ring-red-500' => $hasError,
            ]) }}
        >
    </div>

    @if ($hint)
        <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
    @endif
    <x-ui.field-error :name="$name" :bag="$bag" />
</div>
