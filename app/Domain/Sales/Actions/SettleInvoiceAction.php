<?php

namespace App\Domain\Sales\Actions;

use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customers\Enums\CustomerLedgerType;
use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Services\CustomerLedger;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Inventory\Support\Qty;
use App\Domain\Sales\Enums\CounterEventType;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\PrintDocumentType;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Events\InvoiceSettled;
use App\Domain\Sales\Models\CounterEvent;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\PrintJob;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleItemBatch;
use App\Domain\Sales\Services\CounterEventRecorder;
use App\Domain\Sales\Support\LiveBroadcast;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The cashier receives the invoice and the money from a counter.
 *
 * One transaction: lock the sale, release its reservation, issue the stock FEFO (batch
 * costs → COGS), record the payment in the cashier's open drawer session and mark the
 * sale SETTLED. Settling twice with the same key returns the first settlement.
 */
class SettleInvoiceAction
{
    public function __construct(
        private readonly StockService $stock,
        private readonly CounterEventRecorder $recorder,
        private readonly CustomerLedger $ledger,
    ) {}

    /**
     * @param  array{method?: string|null, reference?: string|null, override_credit_limit?: bool}  $payment
     * @return array{sale: Sale, open_drawer: bool, created: bool, credit_bill: PrintJob|null}
     */
    public function handle(Sale $sale, User $cashier, Terminal $terminal, DrawerSession $session, array $payment, string $idempotencyKey): array
    {
        $result = DB::transaction(function () use ($sale, $cashier, $terminal, $session, $payment, $idempotencyKey): array {
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);

            if ($sale->status === SaleStatus::Settled && $sale->settle_idempotency_key === $idempotencyKey) {
                return [$sale, null, false, null];
            }

            if ($sale->status !== SaleStatus::Invoiced) {
                throw ValidationException::withMessages(['sale' => "{$sale->invoice_no} is {$sale->status->label()}; it cannot be settled."]);
            }

            $session = DrawerSession::query()->lockForUpdate()->findOrFail($session->id);

            if (! $session->isOpen() || $session->holder_user_id !== $cashier->id || $session->terminal_id !== $terminal->id) {
                throw ValidationException::withMessages(['drawer' => 'Open your drawer on this terminal before settling.']);
            }

            $method = PaymentMethod::tryFrom((string) ($payment['method'] ?? '')) ?? $sale->payment_method_intent;
            $reference = trim((string) ($payment['reference'] ?? '')) ?: null;

            if (! in_array($method, PaymentMethod::counterMethods(), true)) {
                throw ValidationException::withMessages(['method' => "{$method->label()} payments are not available yet."]);
            }

            if ($method->needsReference() && $reference === null) {
                throw ValidationException::withMessages(['reference' => "Enter the {$method->label()} reference (slip, transfer or cheque number)."]);
            }

            $customer = $method === PaymentMethod::Credit ? $this->checkCredit($sale, $cashier, (bool) ($payment['override_credit_limit'] ?? false)) : null;

            $costTotal = Money::zero();
            $sale->load('items');
            $products = Product::query()->with('baseUnit')->whereIn('id', $sale->items->pluck('product_id'))->get()->keyBy('id');

            foreach ($sale->items as $item) {
                $this->stock->release($item->reservationAllocations());

                $allocations = $this->stock->issue($products[$item->product_id], $item->variant_id, $item->base_qty, MovementType::Sale, $sale, $cashier->id);
                $itemCost = Money::zero();

                foreach ($allocations as $allocation) {
                    SaleItemBatch::create([
                        'sale_item_id' => $item->id,
                        'batch_id' => $allocation->batchId,
                        'base_qty' => $allocation->qty,
                        'unit_cost' => $allocation->unitCost,
                    ]);
                    $itemCost = $itemCost->plus(Qty::of($allocation->qty)->multipliedBy($allocation->unitCost));
                }

                $itemCost = $itemCost->toScale(2, RoundingMode::HalfUp);
                $item->forceFill(['cost_total' => (string) $itemCost, 'reservations' => null])->save();
                $costTotal = $costTotal->plus($itemCost);
            }

            Payment::create([
                'sale_id' => $sale->id,
                'method' => $method,
                'amount' => $sale->total,
                'tendered' => $method === PaymentMethod::Cash ? $sale->tendered_amount : null,
                'reference' => $reference !== null ? mb_substr($reference, 0, 100) : null,
                'recorded_by' => $sale->invoiced_by,
                'confirmed_by' => $cashier->id,
                'drawer_session_id' => $session->id,
            ]);

            $sale->forceFill([
                'status' => SaleStatus::Settled,
                'payment_method_intent' => $method,
                'settled_by' => $cashier->id,
                'settled_terminal_id' => $terminal->id,
                'settled_at' => now(),
                'drawer_session_id' => $session->id,
                'cost_total' => (string) $costTotal,
                'settle_idempotency_key' => $idempotencyKey,
            ])->save();

            if ($customer !== null) {
                // The customer now owes the invoice; it is due after their credit days.
                $dueDate = today()->addDays($customer->credit_days);
                $sale->forceFill(['balance_due' => $sale->total, 'due_date' => $dueDate])->save();
                $this->ledger->debit($customer->id, CustomerLedgerType::Sale, $sale, $sale->total, today(), $cashier->id, $dueDate);

                // The shop's copy for the customer's signature and the shop seal, on the main printer.
                $creditBill = PrintJob::record(PrintDocumentType::CreditBill, $sale->id, $terminal->loadMissing('printer'), $cashier->id);
            }

            $event = $this->recorder->record($sale->invoiced_terminal_id, $cashier->id, CounterEventType::Settled, null, [
                'total' => $sale->total,
                'method' => $method->value,
            ], $sale);

            return [$sale, $event, true, $creditBill ?? null];
        });

        /** @var Sale $settled */
        [$settled, $event, $created, $creditBill] = $result;

        if ($created && $event instanceof CounterEvent) {
            LiveBroadcast::send(new InvoiceSettled($settled->liveSummary(), [$event->toBroadcast()]));
        }

        return [
            'sale' => $settled,
            'open_drawer' => $settled->payments()->latest('id')->first()?->method === PaymentMethod::Cash,
            'created' => $created,
            'credit_bill' => $creditBill,
        ];
    }

    /**
     * A credit settlement needs the customer on the invoice and room under their credit
     * limit. Only the Super Admin can let it go over (never a delegate).
     */
    private function checkCredit(Sale $sale, User $cashier, bool $override): Customer
    {
        $customer = $sale->customer_id !== null ? Customer::query()->lockForUpdate()->find($sale->customer_id) : null;

        if ($customer === null) {
            throw ValidationException::withMessages(['method' => 'A credit sale needs a customer. Void it and bill again with the customer (F4).']);
        }

        $balance = $customer->balance();
        $after = $balance->plus($sale->total);

        if ($after->isGreaterThan($customer->credit_limit)) {
            if ($override && $cashier->hasRole(Role::SuperAdmin->value)) {
                return $customer;
            }

            $over = Money::format($after->minus($customer->credit_limit));

            throw ValidationException::withMessages(['credit_limit' => "{$customer->name} would be Rs. {$over} over the credit limit (limit Rs. ".Money::format($customer->credit_limit).', owes Rs. '.Money::format($balance).'). Only the owner can allow it.']);
        }

        return $customer;
    }
}
