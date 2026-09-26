<?php

namespace App\Domain\Reports\Exports;

use App\Domain\Reports\Support\Column;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * A report as an .xlsx sheet: headings, one row per report row, a totals row.
 * Numbers stay numbers so the owner can add them up in Excel.
 */
class ReportExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @param  list<Column>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        private readonly string $title,
        private readonly array $columns,
        private readonly array $rows,
    ) {}

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return array_map(fn (Column $column) => $column->label, $this->columns);
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    public function array(): array
    {
        $rows = array_map(
            fn (array $row) => array_map(fn (Column $column) => $column->exportValue($row[$column->key] ?? null), $this->columns),
            $this->rows,
        );

        if ($this->rows !== [] && collect($this->columns)->contains('total', true)) {
            $rows[] = array_map(
                fn (Column $column, int $index) => $column->total ? $column->exportValue($column->sum($this->rows)) : ($index === 0 ? 'Total' : null),
                $this->columns,
                array_keys($this->columns),
            );
        }

        return $rows;
    }

    public function title(): string
    {
        // Excel sheet names: max 31 characters, no []:*?/\
        return mb_substr((string) preg_replace('/[\[\]:*?\/\\\\]/', '', $this->title), 0, 31);
    }
}
