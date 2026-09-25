<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Sales\Enums\CounterEventType;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\PrintDocumentType;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Events\InvoiceIssued;
use App\Domain\Sales\Models\ApprovalRequest;
use App\Domain\Sales\Models\CounterEvent;
use App\Domain\Sales\Models\PrintJob;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Sales\Services\CartPricer;
use App\Domain\Sales\Services\CounterEventRecorder;
use App\Domain\Sales\Services\LiveCartStore;
use App\Domain\Sales\Support\LiveBroadcast;
use App\Domain\Sales\Support\Money;
use App\Domain\System\Services\DocumentNumber;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * F9 at a counter: turn the cart into a numbered invoice that waits for settlement.
 *
 * One transaction: reprice on the server, check the tendered amount, reserve stock,
 * take the next gapless invoice number, write the sale and its lines, log PRINTED and
 * create the print job. A repeated call with the same idempotency key returns the
 * same invoice (double click, retried request).
 */
class IssueCounterInvoiceAction
{
    public function __construct(
        private readonly CartPricer $pricer,
        private readonly StockService $stock,
        private readonly DocumentNumber $numbers,
        private readonly CounterEventRecorder $recorder,
        private readonly LiveCartStore $store,
    ) {}

    /**
     * @param  array<string, mixed>  $cart
     * @return array{sale: Sale, print_job: PrintJob|null, created: bool}
     */
    public function handle(Terminal $terminal, User $user, array $cart, string $idempotencyKey): array
    {
        $existing = Sale::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return ['sale' => $existing, 'print_job' => null, 'created' => false];
        }

        $priced = $this->pricer->price($cart, $user, strict: true);
        $this->validate($priced);

        try {
            [$sale, $printJob, $event] = DB::transaction(fn () => $this->create($terminal, $user, $priced, $idempotencyKey));
        } catch (UniqueConstraintViolationException $exception) {
            // Another request with the same key won the race.
            $sale = Sale::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($sale === null) {
                throw $exception;
            }

            return ['sale' => $sale, 'print_job' => null, 'created' => false];
        }

        $this->store->forget($terminal->id);

        LiveBroadcast::send(new InvoiceIssued($sale->liveSummary(), [$event->toBroadcast()]));

        return ['sale' => $sale, 'print_job' => $printJob, 'created' => true];
    }

    /**
     * @param  array<string, mixed>  $priced
     */
    private function validate(array $priced): void
    {
        if ($priced['lines'] === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one item to the bill.']);
        }

        if ($priced['needs_approval']) {
            throw ValidationException::withMessages(['discount' => 'A discount is waiting for the cashier\'s approval. Wait for it or remove the discount.']);
        }

        $method = PaymentMethod::from($priced['payment_method']);

        if (! in_array($method, PaymentMethod::counterMethods(), true)) {
            throw ValidationException::withMessages(['payment_method' => "{$method->label()} is not available yet."]);
        }

        if ($method === PaymentMethod::Cash) {
            if ($priced['tendered'] === null) {
                throw ValidationException::withMessages(['tendered' => 'Enter the amount the customer gave (F6).']);
            }

            if (Money::of($priced['tendered'])->isLessThan($priced['total'])) {
                throw ValidationException::withMessages(['tendered' => 'The amount tendered is less than the total.']);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $priced
     * @return array{0: Sale, 1: PrintJob, 2: CounterEvent}
     */
    private function create(Terminal $terminal, User $user, array $priced, string $idempotencyKey): array
    {
        $method = PaymentMethod::from($priced['payment_method']);
        $products = Product::query()->with('baseUnit')->whereIn('id', array_column($priced['lines'], 'product_id'))->get()->keyBy('id');

        // Reserve first: if stock is short nothing else has happened yet.
        $reservations = [];

        foreach ($priced['lines'] as $index => $line) {
            $reservations[$index] = $this->stock->reserve($products[$line['product_id']], $line['variant_id'], $line['base_qty']);
        }

        $sale = Sale::create([
            'invoice_no' => $this->numbers->next('SALE'),
            'status' => SaleStatus::Invoiced,
            'price_list_id' => $priced['price_list_id'],
            'cart_uuid' => $priced['cart_uuid'] ?: (string) str()->uuid(),
            'invoiced_by' => $user->id,
            'invoiced_terminal_id' => $terminal->id,
            'invoiced_at' => now(),
            'subtotal' => $priced['subtotal'],
            'line_discount_total' => $priced['line_discount_total'],
            'bill_discount' => $priced['bill_discount'],
            'tax_total' => $priced['tax_total'],
            'total' => $priced['total'],
            'payment_method_intent' => $method,
            'tendered_amount' => $method === PaymentMethod::Cash ? $priced['tendered'] : null,
            'change_due' => $method === PaymentMethod::Cash ? $priced['change_due'] : '0.00',
            'balance_due' => '0.00',
            'idempotency_key' => $idempotencyKey,
            'print_count' => 1,
        ]);

        $approvalIds = array_filter([$priced['bill_approval_request_id']]);

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
                'reservations' => SaleItem::reservationsFrom($reservations[$index]),
                'approval_request_id' => $line['approval_request_id'],
            ]);

            if ($line['approval_request_id'] !== null) {
                $approvalIds[] = $line['approval_request_id'];
            }
        }

        // An approval is used once: it now belongs to this invoice.
        if ($approvalIds !== []) {
            ApprovalRequest::query()->whereIn('id', $approvalIds)->update(['sale_id' => $sale->id]);
        }

        $event = $this->recorder->record($terminal->id, $user->id, CounterEventType::Printed, $sale->cart_uuid, [
            'total' => $sale->total,
            'tendered' => $sale->tendered_amount,
            'change' => $sale->change_due,
            'method' => $method->value,
        ], $sale);

        $printJob = PrintJob::record(PrintDocumentType::Invoice, $sale->id, $terminal->loadMissing('printer'), $user->id);

        return [$sale, $printJob, $event];
    }
}
