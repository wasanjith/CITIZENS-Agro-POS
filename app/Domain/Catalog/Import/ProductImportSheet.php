<?php

namespace App\Domain\Catalog\Import;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Reads an uploaded product file as heading-keyed rows (use with Excel::toArray()).
 * Empty rows are kept so that row numbers in error messages match the spreadsheet.
 */
class ProductImportSheet implements ToArray, WithCalculatedFormulas, WithHeadingRow
{
    /**
     * @param  array<int, array<string, mixed>>  $array
     */
    public function array(array $array): void {}
}
