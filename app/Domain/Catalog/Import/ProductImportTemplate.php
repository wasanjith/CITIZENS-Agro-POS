<?php

namespace App\Domain\Catalog\Import;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Downloadable .xlsx template with the headings and two example rows.
 */
class ProductImportTemplate implements FromArray, ShouldAutoSize, WithHeadings
{
    use Exportable;

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return ProductImportColumns::headings();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        return [
            ['', 'Urea 50kg', 'යූරියා', '', 'yuriya, urea bag', 'Fertilizers', 'Lanka Fertilizer', 'kg', 'bag=50', 'bag', 9000, 8800, 100, 500, 1250, 160, 'L-2409', ''],
            ['', 'Bicycle Tyre 26"', 'බයිසිකල් ටයරය 26"', '', 'tyre 26', 'Bicycle Parts > Tyres', 'DSI', 'piece', '', '', 2250, 2150, 5, 20, 40, 1800, '', ''],
        ];
    }
}
