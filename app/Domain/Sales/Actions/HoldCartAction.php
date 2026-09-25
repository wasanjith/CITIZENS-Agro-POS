<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Identity\Models\Terminal;
use App\Domain\Sales\Enums\CounterEventType;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Events\CounterCartUpdated;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Sales\Services\CartPricer;
use App\Domain\Sales\Services\CounterEventRecorder;
use App\Domain\Sales\Services\LiveCartStore;
use App\Domain\Sales\Support\LiveBroadcast;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * F8: park the bill while the customer decides (e.g. phones someone). A held bill has
 * no invoice number and reserves no stock; recalling it puts the lines back in the cart.
 */
class HoldCartAction
{
    public function __construct(
        private readonly CartPricer $pricer,
        private readonly CounterEventRecorder $recorder,
        private readonly LiveCartStore $store,
    ) {}

    /**
     * @param  array<string, mixed>  $cart
     */
    public function hold(Terminal $terminal, User $user, array $cart, ?string $note = null): Sale
    {
        $priced = $this->pricer->price($cart, $user, strict: false);

        if ($priced['lines'] === []) {
            throw ValidationException::withMessages(['lines' => 'There is nothing to hold.']);
        }

        [$sale, $event] = DB::transaction(function () use ($terminal, $user, $priced, $note): array {
            $sale = Sale::create([
                'status' => SaleStatus::OnHold,
                'price_list_id' => $priced['price_list_id'],
                'cart_uuid' => $priced['cart_uuid'] ?: (string) str()->uuid(),
                'invoiced_by' => $user->id,
                'invoiced_terminal_id' => $terminal->id,
                'subtotal' => $priced['subtotal'],
                'line_discount_total' => $priced['line_discount_total'],
                'bill_discount' => $priced['bill_discount'],
                'tax_total' => $priced['tax_total'],
                'total' => $priced['total'],
                'payment_method_intent' => PaymentMethod::from($priced['payment_method']),
                'note' => $note !== null ? mb_substr($note, 0, 255) : null,
            ]);

            foreach ($priced['lines'] as $index => $line) {
                SaleItem::create([
                    'sale_id' => $sale->id,
                    'line_no' => $index + 1,
                    'product_id' => $line['product_id'],
                    'variant_id' => $line['variant_id'],
                    'unit_id' => $line['unit_id'],
                    'qty' => $line['qty'],
                    'factor' => $line['factor'],
                    'base_qty' => $line['base_qty'],
                    'unit_price' => $line['unit_price'],
                    'discount_amount' => $line['discount'],
                    'tax_amount' => $line['tax'],
                    'line_total' => $line['line_total'],
                    'short_code_snapshot' => $line['short_code'],
                    'name_snapshot' => mb_substr($line['name'], 0, 191),
                    'name_si_snapshot' => $line['name_si'] !== null ? mb_substr($line['name_si'], 0, 191) : null,
                    'unit_snapshot' => $line['unit'],
                    'unit_si_snapshot' => $line['unit_si'],
                    'approval_request_id' => $line['approval_request_id'],
                ]);
            }

            $event = $this->recorder->record($terminal->id, $user->id, CounterEventType::Held, $sale->cart_uuid, [
                'lines' => count($priced['lines']),
                'total' => $priced['total'],
            ], $sale);

            return [$sale, $event];
        });

        $this->store->forget($terminal->id);
        LiveBroadcast::send(new CounterCartUpdated($terminal->id, ['terminal_id' => $terminal->id, 'lines' => [], 'status' => 'idle', 'total' => '0.00'], [$event->toBroadcast()]));

        return $sale;
    }

    /**
     * Take a held bill back into the cart. The held row is removed (it never had a
     * number); the RECALLED event keeps the trail.
     *
     * @return array<string, mixed> the cart to load on the counter
     */
    public function recall(Terminal $terminal, User $user, Sale $sale): array
    {
        return DB::transaction(function () use ($terminal, $user, $sale): array {
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);

            if ($sale->status !== SaleStatus::OnHold || $sale->invoiced_terminal_id !== $terminal->id) {
                throw ValidationException::withMessages(['sale' => 'This bill is no longer on hold at this counter.']);
            }

            $sale->load('items');

            $cart = [
                'cart_uuid' => $sale->cart_uuid,
                'price_list_id' => $sale->price_list_id,
                'payment_method' => $sale->payment_method_intent->value,
                'bill_discount' => $sale->bill_discount,
                'tendered' => null,
                'lines' => $sale->items->map(fn (SaleItem $item) => [
                    'key' => "{$item->product_id}-".($item->variant_id ?? 0)."-{$item->unit_id}-{$item->line_no}",
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'unit_id' => $item->unit_id,
                    'qty' => $item->qty,
                    'discount' => $item->discount_amount,
                ])->all(),
            ];

            $this->recorder->record($terminal->id, $user->id, CounterEventType::Recalled, $sale->cart_uuid, [
                'lines' => $sale->items->count(),
                'total' => $sale->total,
            ]);

            $sale->items()->delete();
            $sale->delete();

            return $cart;
        });
    }
}
