<?php

namespace App\Domain\Customers\Actions;

use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\Customers\Enums\CustomerLedgerType;
use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Models\CustomerPayment;
use App\Domain\Customers\Models\CustomerPaymentAllocation;
use App\Domain\Customers\Services\CustomerLedger;
use App\Domain\Finance\Services\FinancePosting;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\PrintDocumentType;
use App\Domain\Sales\Models\PrintJob;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Support\Money;
use App\Domain\System\Services\DocumentNumber;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A customer pays towards their account at the main cashier.
 *
 * One transaction: the payment is applied to their credit invoices, oldest due first
 * (FIFO) or as the cashier chose, each invoice's balance_due goes down, the ledger gets
 * a credit, and cash lands in the cashier's drawer session. Money left over stays on
 * the account as an advance. The Sinhala receipt prints on the main printer.
 */
class ReceiveCustomerPaymentAction
{
    public function __construct(
        private readonly DocumentNumber $numbers,
        private readonly CustomerLedger $ledger,
        private readonly FinancePosting $finance,
    ) {}

    /**
     * allocations: sale_id => amount chosen by the cashier; null or empty = oldest first.
     *
     * @param  array{amount: string, method: string, reference?: string|null, note?: string|null, allocations?: array<int|string, string|null>|null, cheque_bank?: string|null, cheque_branch?: string|null, cheque_date?: string|null}  $data
     * @return array{payment: CustomerPayment, print_job: PrintJob|null, created: bool}
     */
    public function handle(Customer $customer, User $cashier, Terminal $terminal, DrawerSession $session, array $data, string $idempotencyKey): array
    {
        $existing = CustomerPayment::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return ['payment' => $existing, 'print_job' => null, 'created' => false];
        }

        $amount = Money::of($data['amount']);
        $method = PaymentMethod::tryFrom($data['method']);
        $reference = trim((string) ($data['reference'] ?? '')) ?: null;

        if (! $amount->isPositive()) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount received.']);
        }

        if ($method === null || ! in_array($method, PaymentMethod::customerPaymentMethods(), true)) {
            throw ValidationException::withMessages(['method' => 'Choose how the customer paid.']);
        }

        if ($method->needsReference() && $reference === null) {
            throw ValidationException::withMessages(['reference' => "Enter the {$method->label()} reference (slip, transfer or cheque number)."]);
        }

        try {
            [$payment, $printJob] = DB::transaction(function () use ($customer, $cashier, $terminal, $session, $data, $amount, $method, $reference, $idempotencyKey): array {
                $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
                $session = DrawerSession::query()->lockForUpdate()->findOrFail($session->id);

                if (! $session->isOpen() || $session->holder_user_id !== $cashier->id || $session->terminal_id !== $terminal->id) {
                    throw ValidationException::withMessages(['drawer' => 'Open your drawer on this terminal before taking payments.']);
                }

                $open = $customer->openCreditSales()->lockForUpdate()->get();
                $allocations = ($data['allocations'] ?? null) !== null && $data['allocations'] !== []
                    ? $this->manualAllocations($open, $data['allocations'], $amount)
                    : $this->oldestFirst($open, $amount);

                $payment = CustomerPayment::create([
                    'number' => $this->numbers->next('RCP'),
                    'customer_id' => $customer->id,
                    'date' => today(),
                    'amount' => (string) $amount,
                    'method' => $method,
                    'reference' => $reference !== null ? mb_substr($reference, 0, 100) : null,
                    'drawer_session_id' => $session->id,
                    'terminal_id' => $terminal->id,
                    'received_by' => $cashier->id,
                    'note' => isset($data['note']) && $data['note'] !== '' ? mb_substr((string) $data['note'], 0, 255) : null,
                    'idempotency_key' => $idempotencyKey,
                ]);

                foreach ($allocations as $saleId => $allocated) {
                    CustomerPaymentAllocation::create(['customer_payment_id' => $payment->id, 'sale_id' => $saleId, 'amount' => (string) $allocated]);
                    $sale = $open->firstWhere('id', $saleId);
                    $sale?->forceFill(['balance_due' => (string) Money::of($sale->balance_due)->minus($allocated)])->save();
                }

                $this->ledger->credit($customer->id, CustomerLedgerType::Payment, $payment, $amount, today(), $cashier->id, $method->label().($reference !== null ? " {$reference}" : ''));
                $this->finance->customerPaymentReceived($payment, [
                    'bank_name' => $data['cheque_bank'] ?? null,
                    'branch' => $data['cheque_branch'] ?? null,
                    'cheque_date' => $data['cheque_date'] ?? null,
                ], $cashier->id);

                return [$payment, PrintJob::record(PrintDocumentType::PaymentReceipt, $payment->id, $terminal->loadMissing('printer'), $cashier->id)];
            });
        } catch (UniqueConstraintViolationException $exception) {
            $payment = CustomerPayment::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($payment === null) {
                throw $exception;
            }

            return ['payment' => $payment, 'print_job' => null, 'created' => false];
        }

        return ['payment' => $payment, 'print_job' => $printJob, 'created' => true];
    }

    /**
     * FIFO: pay the invoice due first, then the next, until the money runs out.
     *
     * @param  Collection<int, Sale>  $open
     * @return array<int, BigDecimal> sale_id => amount
     */
    private function oldestFirst(Collection $open, BigDecimal $amount): array
    {
        $left = $amount;
        $allocations = [];

        foreach ($open as $sale) {
            if (! $left->isPositive()) {
                break;
            }

            $part = Money::of($sale->balance_due)->isLessThan($left) ? Money::of($sale->balance_due) : $left;
            $allocations[$sale->id] = $part;
            $left = $left->minus($part);
        }

        return $allocations;
    }

    /**
     * The cashier chose the invoices. Each part must fit what is owed on that invoice and
     * together they cannot exceed the payment.
     *
     * @param  Collection<int, Sale>  $open
     * @param  array<int|string, string|null>  $requested
     * @return array<int, BigDecimal>
     */
    private function manualAllocations(Collection $open, array $requested, BigDecimal $amount): array
    {
        $allocations = [];
        $total = Money::zero();

        foreach ($requested as $saleId => $value) {
            $part = Money::of($value);

            if ($part->isZero()) {
                continue;
            }

            $sale = $open->firstWhere('id', (int) $saleId);

            if ($sale === null) {
                throw ValidationException::withMessages(['allocations' => 'One of the invoices is not an unpaid credit invoice of this customer.']);
            }

            if ($part->isNegative() || $part->isGreaterThan($sale->balance_due)) {
                throw ValidationException::withMessages(["allocations.{$sale->id}" => "{$sale->invoice_no}: pay between 0 and Rs. ".Money::format($sale->balance_due).'.']);
            }

            $allocations[$sale->id] = $part;
            $total = $total->plus($part);
        }

        if ($total->isGreaterThan($amount)) {
            throw ValidationException::withMessages(['allocations' => 'The amounts for the invoices add up to more than the payment.']);
        }

        return $allocations;
    }
}
