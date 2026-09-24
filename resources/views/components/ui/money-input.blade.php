@props(['name', 'label' => null, 'value' => null, 'hint' => null, 'required' => false])

<x-ui.input
    :name="$name"
    :label="$label"
    :value="$value"
    :hint="$hint"
    :required="$required"
    type="text"
    inputmode="decimal"
    prefix="Rs."
    pattern="^\d+(\.\d{1,2})?$"
    autocomplete="off"
    {{ $attributes }}
/>
