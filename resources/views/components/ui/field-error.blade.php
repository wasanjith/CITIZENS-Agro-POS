@props(['name', 'bag' => 'default'])

@php
    $key = str_replace(['[', ']'], ['.', ''], $name);
@endphp

@if ($errors->getBag($bag)->has($key))
    <p {{ $attributes->merge(['class' => 'mt-1 text-sm text-red-600']) }}>{{ $errors->getBag($bag)->first($key) }}</p>
@endif
