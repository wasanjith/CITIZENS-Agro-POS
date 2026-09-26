<?php

namespace App\Domain\Sales\Actions;

use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customers\Enums\CustomerLedgerType;
use App\Domain\Customers\Services\CustomerLedger;
use App\Domain\Finance\Services\FinancePosting;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Sales\Enums\CounterEventType;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Events\InvoiceVoided;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Sales\Services\CounterEventRecorder;
use App\Domain\Sales\Services\LiveCartStore;
use App\Domain\Sales\Support\LiveBroadcast;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancel an invoice with a reason. The invoice keeps its number and shows as VOID, so
 * numbering stays gapless.
 *
 *  - Waiting (INVOICED): the stock reservation is released and the counter that printed
 *    it is offered the cart back for re-billing.
 *  - Settled today: the stock goes back into the batches it came from and the money is
 *    refunded from the cashier's open drawer session.
 */
class VoidInvoiceAction
{
    public function __construct(
        private readonly StockService $stock,
        private readonly CounterEventRecorder $recorder,
        private readonly LiveCartStore $store,
        private readonly CustomerLedger $ledger,
        private readonly FinancePosting $finance,
    ) {}

    public function handle(Sale $sale, User $user, string $reason, ?DrawerSession $session = null): Sale
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Enter the reason for the void.']);
        }

        [$sale, $event, $wasInvoiced] = DB::transaction(function () use ($sale, $user, $reason, $session): array {
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            $sale->load('items.batches');
            $wasInvoiced = $sale->status === SaleStatus::Invoiced;

            if ($wasInvoiced) {
                foreach ($sale->items as $item) {
                    $this->stock->release($item->reservationAllocations());
                    $item->forceFill(['reservations' => null])->save();
                }
            } elseif ($sale->status === SaleStatus::Settled) {
                $this->reverseSettlement($sale, $user, $reason, $session);
            } else {
                throw ValidationException::withMessages(['sale' => "{$sale->invoice_no} is {$sale->status->label()}; it cannot be voided."]);
            }

            $sale->forceFill([
                'status' => SaleStatus::Void,
                'void_reason' => mb_substr($reason, 0, 255),
                'voided_by' => $user->id,
                'voided_at' => now(),
            ])->save();

            $event = $this->recorder->record($sale->invoiced_terminal_id, $user->id, CounterEventType::Voided, null, [
                'total' => $sale->total,
                'reason' => $reason,
                'was' => $wasInvoiced ? 'invoiced' : 'settled',
            ], $sale);

            return [$sale, $event, $wasInvoiced];
        });

        $restore = $wasInvoiced ? $this->restoreCart($sale) : null;

        if ($restore !== null) {
            $this->store->pushVoidRestore($sale->invoiced_terminal_id, $restore);
        }

        LiveBroadcast::send(new InvoiceVoided($sale->invoiced_terminal_id, $sale->liveSummary(), $restore, [$event->toBroadcast()]));

        return $sale;
    }

    private function reverseSettlement(Sale $sale, User $user, string $reason, ?DrawerSession $session): void
    {
        if ($sale->settled_at === null || ! $sale->settled_at->isToday()) {
            throw ValidationException::withMessages(['sale' => 'Only sales settled today can be voided. Use a sale return for older sales.']);
        }

        $session = $session !== null ? DrawerSession::query()->lockForUpdate()->find($session->id) : null;

        if ($session === null || ! $session->isOpen() || $session->holder_user_id !== $user->id) {
            throw ValidationException::withMessages(['drawer' => 'Open your drawer first: the refund is paid out of it.']);
        }

        if ($sale->allocations()->exists()) {
            throw ValidationException::withMessages(['sale' => "The customer has already paid towards {$sale->invoice_no}. Use a sale return instead."]);
        }

        if ($sale->isCreditSale() && (float) $sale->balance_due > 0) {
            $this->ledger->credit((int) $sale->customer_id, CustomerLedgerType::Adjustment, $sale, $sale->balance_due, today(), $user->id, "Void: {$reason}");
            $sale->forceFill(['balance_due' => '0.00'])->save();
        }

        $products = Product::query()->whereIn('id', $sale->items->pluck('product_id'))->get()->keyBy('id');

        foreach ($sale->items as $item) {
            foreach ($item->batches as $allocation) {
                $this->stock->adjust(
                    $products[$item->product_id],
                    $item->variant_id,
                    Batch::query()->findOrFail($allocation->batch_id),
                    $allocation->base_qty,
                    MovementType::SaleReturn,
                    $sale,
                    $user->id,
                    "Void of {$sale->invoice_no}: {$reason}",
                );
            }
        }

        foreach ($sale->payments()->where('amount', '>', 0)->get() as $payment) {
            Payment::create([
                'sale_id' => $sale->id,
                'method' => $payment->method,
                'amount' => '-'.$payment->amount,
                'reference' => $payment->reference,
                'recorded_by' => $user->id,
                'confirmed_by' => $user->id,
                'drawer_session_id' => $session->id,
            ]);
        }

        $this->finance->saleVoided($sale, $user->id);
    }

    /**
     * The invoice's lines as a cart the counter can load again (new cart, new number).
     *
     * @return array<string, mixed>
     */
    private function restoreCart(Sale $sale): array
    {
        return [
            'sale_id' => $sale->id,
            'invoice_no' => $sale->invoice_no,
            'total' => $sale->total,
            'reason' => $sale->void_reason,
            'cart' => [
                'price_list_id' => $sale->price_list_id,
                'customer_id' => $sale->customer_id,
                'payment_method' => $sale->payment_method_intent->value,
                'bill_discount' => $sale->bill_discount,
                'lines' => $sale->items->map(fn (SaleItem $item) => [
                    'key' => "{$item->product_id}-".($item->variant_id ?? 0)."-{$item->unit_id}-{$item->line_no}",
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'unit_id' => $item->unit_id,
                    'qty' => $item->qty,
                    'discount' => $item->discount_amount,
                ])->all(),
            ],
        ];
    }
}
