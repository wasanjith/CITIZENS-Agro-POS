@props(['name', 'label' => null, 'value' => null, 'hint' => null, 'required' => false, 'decimals' => 3])

<x-ui.input
    :name="$name"
    :label="$label"
    :value="$value"
    :hint="$hint"
    :required="$required"
    type="number"
    inputmode="decimal"
    min="0"
    step="{{ $decimals > 0 ? '0.'.str_repeat('0', $decimals - 1).'1' : '1' }}"
    {{ $attributes }}
/>
