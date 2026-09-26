<?php

namespace App\Domain\Reports;

use App\Domain\Reports\Support\Column;
use App\Domain\Reports\Support\Filter;
use App\Domain\Reports\Support\ReportGroup;
use App\Domain\Reports\Support\ReportInput;
use App\Domain\Reports\Support\ReportResult;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * A report shown by the generic report page: filter bar → table → Excel / PDF.
 * Subclasses say what they are, who may see them, their filters and columns, and build the rows.
 */
abstract class Report
{
    /**
     * URL key: /reports/{key}.
     */
    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function group(): ReportGroup;

    public function description(): string
    {
        return '';
    }

    /**
     * Permission(s) that open the report (any of them).
     *
     * @return string|list<string>
     */
    abstract public function permission(): string|array;

    /**
     * False for "as of now" reports (stock on hand, ageing …): no date range on the filter bar.
     */
    public function usesPeriod(): bool
    {
        return true;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function defaultPeriod(): array
    {
        return [today()->startOfMonth(), today()];
    }

    /**
     * @return list<Filter>
     */
    public function filters(User $user): array
    {
        return [];
    }

    /**
     * @return list<Column>
     */
    abstract public function columns(ReportInput $input): array;

    abstract public function run(ReportInput $input): ReportResult;

    /**
     * A4 landscape for wide tables.
     */
    public function landscape(ReportInput $input): bool
    {
        return count($this->columns($input)) > 7;
    }

    public function canBeViewedBy(User $user): bool
    {
        return $user->canAny((array) $this->permission());
    }

    /**
     * Report subtitle: the range (or "as of"), plus what the filters are set to.
     */
    public function subtitle(ReportInput $input): string
    {
        $parts = [$this->usesPeriod()
            ? ($input->from->eq($input->to) ? $input->from->format('Y-m-d') : $input->from->format('Y-m-d').' to '.$input->to->format('Y-m-d'))
            : 'As of '.now()->format('Y-m-d H:i')];

        foreach ($this->filters($input->user) as $filter) {
            $value = $input->get($filter->name);

            if ($value === null || ($filter->default !== null && $value === $filter->default && $filter->type !== 'number')) {
                continue;
            }

            $parts[] = match ($filter->type) {
                'select' => $filter->label.': '.($filter->options[$value] ?? $value),
                'checkbox' => $filter->label,
                default => $filter->label.': '.$value,
            };
        }

        return implode(' · ', $parts);
    }
}
