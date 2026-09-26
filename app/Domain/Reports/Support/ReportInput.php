<?php

namespace App\Domain\Reports\Support;

use App\Domain\Reports\Report;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * What a report is run for: the date range, the extra filters and the user (some columns,
 * such as cost and profit, depend on what the user may see). Built from the query string,
 * so a queued export can rebuild exactly the same input.
 */
final class ReportInput
{
    /**
     * @param  array<string, string|null>  $values
     */
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        private readonly array $values,
        public readonly User $user,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(Report $report, array $query, User $user): self
    {
        [$defaultFrom, $defaultTo] = $report->defaultPeriod();
        $from = self::date($query['from'] ?? null) ?? $defaultFrom->copy()->startOfDay();
        $to = self::date($query['to'] ?? null) ?? $defaultTo->copy()->startOfDay();

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        $values = [];

        foreach ($report->filters($user) as $filter) {
            $values[$filter->name] = $filter->clean($query[$filter->name] ?? null);
        }

        return new self($from, $to, $values, $user);
    }

    public function get(string $name): ?string
    {
        return $this->values[$name] ?? null;
    }

    public function int(string $name, int $default = 0): int
    {
        $value = $this->get($name);

        return $value !== null ? (int) $value : $default;
    }

    public function flag(string $name): bool
    {
        return $this->get($name) === '1';
    }

    /**
     * First moment after the range (the range is whole days, `to` included).
     */
    public function end(): Carbon
    {
        return $this->to->copy()->addDay()->startOfDay();
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /**
     * The input as a query string array (for links, exports and queued jobs).
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            ...$this->values,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)?->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
