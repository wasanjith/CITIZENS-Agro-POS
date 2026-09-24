@props(['name', 'label' => null, 'value' => null, 'hint' => null, 'required' => false])

<x-ui.input
    :name="$name"
    :label="$label"
    :value="$value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value"
    :hint="$hint"
    :required="$required"
    type="date"
    {{ $attributes }}
/>
