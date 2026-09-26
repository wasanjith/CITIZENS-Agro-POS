<?php

namespace App\Domain\Reports\Support;

use App\Domain\Sales\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * One column of a report table: what it shows, how it is formatted and whether it is totalled.
 */
final class Column
{
    public const NUMERIC = ['money', 'qty', 'int', 'percent', 'number'];

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly bool $total = false,
    ) {}

    public static function text(string $key, string $label): self
    {
        return new self($key, $label);
    }

    public static function date(string $key, string $label): self
    {
        return new self($key, $label, 'date');
    }

    public static function dateTime(string $key, string $label): self
    {
        return new self($key, $label, 'datetime');
    }

    public static function money(string $key, string $label, bool $total = true): self
    {
        return new self($key, $label, 'money', $total);
    }

    public static function qty(string $key, string $label, bool $total = false): self
    {
        return new self($key, $label, 'qty', $total);
    }

    public static function int(string $key, string $label, bool $total = true): self
    {
        return new self($key, $label, 'int', $total);
    }

    public static function percent(string $key, string $label): self
    {
        return new self($key, $label, 'percent');
    }

    /**
     * A plain number with one decimal (hours, minutes on average …).
     */
    public static function number(string $key, string $label, bool $total = false): self
    {
        return new self($key, $label, 'number', $total);
    }

    public function isNumeric(): bool
    {
        return in_array($this->type, self::NUMERIC, true);
    }

    /**
     * The value as shown on screen and in the PDF.
     */
    public function format(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($this->type) {
            'money' => Money::format((string) $value),
            'qty' => rtrim(rtrim(number_format((float) (string) $value, 3), '0'), '.'),
            'int' => number_format((int) $value),
            'percent' => number_format((float) (string) $value, 1).' %',
            'number' => number_format((float) (string) $value, 1),
            'date' => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10),
            'datetime' => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i') : substr((string) $value, 0, 16),
            default => $value instanceof \BackedEnum ? (string) $value->value : (string) $value,
        };
    }

    /**
     * The value for Excel: numbers stay numbers, dates stay text.
     */
    public function exportValue(mixed $value): string|int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($this->type) {
            'money' => (float) (string) Money::of((string) $value),
            'qty', 'percent', 'number' => round((float) (string) $value, 3),
            'int' => (int) $value,
            default => $this->format($value),
        };
    }

    /**
     * Sum of this column over the rows (exact decimals).
     *
     * @param  iterable<array<string, mixed>>  $rows
     */
    public function sum(iterable $rows): string
    {
        $sum = BigDecimal::zero();

        foreach ($rows as $row) {
            $value = $row[$this->key] ?? null;

            if ($value !== null && $value !== '') {
                $sum = $sum->plus(BigDecimal::of(is_float($value) ? (string) round($value, 4) : (string) $value));
            }
        }

        return match ($this->type) {
            'money' => (string) $sum->toScale(2, RoundingMode::HalfUp),
            'int' => (string) $sum->toScale(0, RoundingMode::HalfUp),
            default => (string) $sum->toScale(3, RoundingMode::HalfUp),
        };
    }
}
