<?php

namespace App\Domain\Purchasing\Enums;

use Illuminate\Support\Str;

/**
 * Kinds of paper kept in a supplier's file (Purchasing → Invoices).
 */
enum SupplierDocumentType: string
{
    case Invoice = 'invoice';
    case DepositSlip = 'deposit_slip';
    case Receipt = 'receipt';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Invoice',
            self::DepositSlip => 'Bank deposit slip',
            self::Receipt => 'Receipt',
            self::Other => 'Other',
        };
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::Invoice => 'Invoices',
            self::DepositSlip => 'Deposit slips',
            self::Receipt => 'Receipts',
            self::Other => 'Other',
        };
    }

    /**
     * "1 invoice", "3 deposit slips", "2 other" for the folder tiles.
     */
    public function countLabel(int $count): string
    {
        $noun = match ($this) {
            self::Invoice => 'invoice',
            self::DepositSlip => 'deposit slip',
            self::Receipt => 'receipt',
            self::Other => 'other',
        };

        return $count.' '.($this === self::Other ? $noun : Str::plural($noun, $count));
    }

    public function color(): string
    {
        return match ($this) {
            self::Invoice => 'blue',
            self::DepositSlip => 'purple',
            self::Receipt => 'green',
            self::Other => 'gray',
        };
    }

    /**
     * Sub-folder inside the supplier's folder.
     */
    public function folder(): string
    {
        return match ($this) {
            self::Invoice => 'invoices',
            self::DepositSlip => 'deposit-slips',
            self::Receipt => 'receipts',
            self::Other => 'other',
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
