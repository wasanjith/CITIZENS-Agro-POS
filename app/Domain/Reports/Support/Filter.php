<?php

namespace App\Domain\Reports\Support;

/**
 * An extra filter on a report's filter bar (besides the date range).
 * select: one of $options · number: a whole number · search: free text · checkbox: yes / no.
 */
final class Filter
{
    /**
     * @param  array<string, string>  $options
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type = 'select',
        public readonly array $options = [],
        public readonly ?string $default = null,
        public readonly ?string $placeholder = null,
    ) {}

    /**
     * @param  array<string|int, string>  $options
     */
    public static function select(string $name, string $label, array $options, ?string $placeholder = 'All', ?string $default = null): self
    {
        return new self($name, $label, 'select', array_combine(array_map('strval', array_keys($options)), array_values($options)), $default, $placeholder);
    }

    public static function number(string $name, string $label, int $default): self
    {
        return new self($name, $label, 'number', default: (string) $default);
    }

    public static function search(string $name, string $label, string $placeholder = ''): self
    {
        return new self($name, $label, 'search', placeholder: $placeholder);
    }

    public static function checkbox(string $name, string $label): self
    {
        return new self($name, $label, 'checkbox');
    }

    /**
     * The submitted value if it is valid for this filter, else the default.
     */
    public function clean(mixed $value): ?string
    {
        if (! is_scalar($value) || (string) $value === '') {
            return $this->default;
        }

        $value = trim((string) $value);

        return match ($this->type) {
            'select' => array_key_exists($value, $this->options) ? $value : $this->default,
            'number' => preg_match('/^\d{1,5}$/', $value) ? $value : $this->default,
            'checkbox' => $value === '1' ? '1' : null,
            default => mb_substr($value, 0, 100),
        };
    }
}
