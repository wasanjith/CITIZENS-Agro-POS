<?php

namespace App\Domain\System\Import;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Downloadable .xlsx template for the customer or supplier import, with one example row.
 */
class OpeningBalanceTemplate implements FromArray, ShouldAutoSize, WithHeadings
{
    use Exportable;

    public function __construct(private readonly string $type) {}

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return OpeningBalanceImporter::headings($this->type);
    }

    /**
     * @return list<list<string|int>>
     */
    public function array(): array
    {
        return OpeningBalanceImporter::exampleRows($this->type);
    }
}
