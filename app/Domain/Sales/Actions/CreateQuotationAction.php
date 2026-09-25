<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Identity\Models\Terminal;
use App\Domain\Sales\Enums\PrintDocumentType;
use App\Domain\Sales\Enums\QuotationStatus;
use App\Domain\Sales\Models\PrintJob;
use App\Domain\Sales\Models\Quotation;
use App\Domain\Sales\Models\QuotationLine;
use App\Domain\Sales\Services\CartPricer;
use App\Domain\System\Services\DocumentNumber;
use App\Domain\System\Services\Settings;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * F7 at a counter: turn the cart into a numbered quotation and print it. Nothing is
 * reserved; prices are the server's prices today. The cart stays on the counter.
 */
class CreateQuotationAction
{
    public function __construct(
        private readonly CartPricer $pricer,
        private readonly DocumentNumber $numbers,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $cart
     * @return array{quotation: Quotation, print_job: PrintJob|null, created: bool}
     */
    public function handle(Terminal $terminal, User $user, array $cart, string $idempotencyKey, ?string $customerName = null, ?string $note = null): array
    {
        $existing = Quotation::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return ['quotation' => $existing, 'print_job' => null, 'created' => false];
        }

        $priced = $this->pricer->price($cart, $user, strict: true);

        if ($priced['lines'] === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one item to the quotation.']);
        }

        if ($priced['needs_approval']) {
            throw ValidationException::withMessages(['discount' => 'A discount is over your limit. Get it approved or remove it before printing a quotation.']);
        }

        try {
            return DB::transaction(function () use ($terminal, $user, $priced, $idempotencyKey, $customerName, $note): array {
                $quotation = Quotation::create([
                    'number' => $this->numbers->next('QUO'),
                    'status' => QuotationStatus::Open,
                    'customer_id' => $priced['customer_id'],
                    'customer_name' => $priced['customer_id'] === null && $customerName !== null && trim($customerName) !== '' ? mb_substr(trim($customerName), 0, 150) : null,
                    'price_list_id' => $priced['price_list_id'],
                    'valid_until' => today()->addDays((int) $this->settings->get('customers.quotation_valid_days', 7)),
                    'subtotal' => $priced['subtotal'],
                    'line_discount_total' => $priced['line_discount_total'],
                    'bill_discount' => $priced['bill_discount'],
                    'tax_total' => $priced['tax_total'],
                    'total' => $priced['total'],
                    'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
                    'terminal_id' => $terminal->id,
                    'created_by' => $user->id,
                    'idempotency_key' => $idempotencyKey,
                ]);

                foreach ($priced['lines'] as $index => $line) {
                    QuotationLine::create([
                        'quotation_id' => $quotation->id,
                        'line_no' => $index + 1,
                        'product_id' => $line['product_id'],
                        'variant_id' => $line['variant_id'],
                        'unit_id' => $line['unit_id'],
                        'qty' => $line['qty'],
                        'factor' => $line['factor'],
                        'base_qty' => $line['base_qty'],
                        'unit_price' => $line['unit_price'],
                        'discount_amount' => $line['discount'],
                        'line_total' => $line['line_total'],
                        'short_code_snapshot' => $line['short_code'],
                        'name_snapshot' => mb_substr($line['name'], 0, 191),
                        'name_si_snapshot' => $line['name_si'] !== null ? mb_substr($line['name_si'], 0, 191) : null,
                        'unit_snapshot' => $line['unit'],
                        'unit_si_snapshot' => $line['unit_si'],
                    ]);
                }

                return [
                    'quotation' => $quotation,
                    'print_job' => PrintJob::record(PrintDocumentType::Quotation, $quotation->id, $terminal->loadMissing('printer'), $user->id),
                    'created' => true,
                ];
            });
        } catch (UniqueConstraintViolationException $exception) {
            $quotation = Quotation::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($quotation === null) {
                throw $exception;
            }

            return ['quotation' => $quotation, 'print_job' => null, 'created' => false];
        }
    }
}
