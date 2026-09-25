<?php

namespace App\Domain\Sales\Enums;

enum PrintDocumentType: string
{
    case Invoice = 'invoice';
    case PaymentReceipt = 'payment_receipt';
    case ZReport = 'z_report';
    case Handover = 'handover';
    case Test = 'test';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Invoice',
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
