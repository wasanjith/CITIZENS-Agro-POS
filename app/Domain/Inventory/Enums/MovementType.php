<?php

namespace App\Domain\Inventory\Enums;

/**
 * Why stock moved. Quantities on stock_movements are signed (+ in, − out).
 */
enum MovementType: string
{
    case Opening = 'opening';
    case Grn = 'grn';
    case Sale = 'sale';
    case SaleReturn = 'sale_return';
    case SupplierReturn = 'supplier_return';
    case AdjustIn = 'adjust_in';
    case AdjustOut = 'adjust_out';
    case Damage = 'damage';
    case Stocktake = 'stocktake';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening stock',
            self::Grn => 'Goods received',
            self::Sale => 'Sale',
            self::SaleReturn => 'Sale return',
            self::SupplierReturn => 'Return to supplier',
            self::AdjustIn => 'Adjustment (in)',
            self::AdjustOut => 'Adjustment (out)',
            self::Damage => 'Damage',
            self::Stocktake => 'Stocktake',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $type) => [$type->value => $type->label()])->all();
    }
}
