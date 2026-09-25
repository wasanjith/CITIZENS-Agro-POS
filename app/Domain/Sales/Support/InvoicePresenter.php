<?php

namespace App\Domain\Sales\Support;

use App\Domain\Identity\Models\Terminal;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\System\Services\Settings;

/**
 * Turns a sale into the data the invoice templates (80 mm and A4) print.
 */
class InvoicePresenter
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * Receipt language: the terminal's own setting, else the shop setting.
     */
    public function language(?Terminal $terminal = null, ?string $override = null): string
    {
        $allowed = ['si', 'en', 'si+en'];

        if (in_array($override, $allowed, true)) {
            return $override;
        }

        if ($terminal !== null && in_array($terminal->receipt_language, $allowed, true)) {
            return $terminal->receipt_language;
        }

        $language = $this->settings->get('receipt.language', 'si');

        return in_array($language, $allowed, true) ? $language : 'si';
    }

    /**
     * @return array<string, mixed> view data for print.partials.invoice-body
     */
    public function present(Sale $sale, string $language, bool $isCopy): array
    {
        $sale->loadMissing(['items', 'invoicedBy', 'invoicedTerminal']);
        $method = $sale->payment_method_intent;
        $isCash = $method === PaymentMethod::Cash;

        return [
            'language' => $language,
            'shop' => $this->settings->group('shop'),
            'receipt' => $this->settings->group('receipt'),
            'invoice' => [
                'number' => $sale->invoice_no,
                'date' => $sale->invoiced_at ?? $sale->created_at,
                'counter' => $sale->invoicedTerminal->counter_no ?? $sale->invoicedTerminal->code,
                'staff' => $sale->invoicedBy->name,
                'items' => $sale->items->map(fn (SaleItem $item) => [
                    'name' => $item->name_snapshot,
                    'name_si' => $item->name_si_snapshot,
                    'qty' => (float) $item->qty,
                    'unit' => $item->unit_snapshot,
                    'unit_si' => $item->unit_si_snapshot ?: $item->unit_snapshot,
                    'price' => (float) $item->unit_price,
                    'discount' => (float) $item->discount_amount,
                    'total' => (float) $item->line_total,
                ])->all(),
                'subtotal' => (float) $sale->subtotal,
                'discount' => (float) $sale->line_discount_total + (float) $sale->bill_discount,
                'total' => (float) $sale->total,
                'method' => $method->value,
                'is_cash' => $isCash,
                'tendered' => $isCash ? (float) $sale->tendered_amount : (float) $sale->total,
                'balance' => $isCash ? (float) $sale->change_due : 0.0,
                'is_copy' => $isCopy,
                'is_void' => $sale->status === SaleStatus::Void,
                'is_sample' => false,
            ],
        ];
    }
}
