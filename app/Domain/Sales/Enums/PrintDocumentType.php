<?php

namespace App\Domain\Sales\Enums;

enum PrintDocumentType: string
{
    case Invoice = 'invoice';
    case PaymentReceipt = 'payment_receipt';
    case ZReport = 'z_report';
    case Handover = 'handover';
    case Test = 'test';
    case SaleReturn = 'sale_return';
    case Quotation = 'quotation';
    case CreditBill = 'credit_bill';

    public function label(): string
    {
        return match ($this) {
            self::CreditBill => 'Credit bill (signed)',
            self::Invoice => 'Invoice',
            self::SaleReturn => 'Return receipt',
            self::Quotation => 'Quotation',
            self::PaymentReceipt => 'Payment receipt',
            self::ZReport => 'Z report',
            self::Handover => 'Handover slip',
            self::Test => 'Test print',
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
