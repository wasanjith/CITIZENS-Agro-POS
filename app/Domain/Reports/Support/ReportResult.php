<?php

namespace App\Domain\Reports\Support;

/**
 * The rows of a report plus optional summary tiles and notes shown above the table.
 * A row is keyed by column key; `_url` links the first column, `_alert` shows the row in red.
 */
final class ReportResult
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $tiles  label => formatted value
     * @param  list<string>  $notes
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $tiles = [],
        public readonly array $notes = [],
    ) {}
}
