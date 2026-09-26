<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\CashDrawer\Enums\CashMovementType;
use App\Domain\Finance\Enums\BankTransactionType;
use App\Domain\Finance\Enums\ChequeDirection;
use App\Domain\Finance\Enums\ChequeStatus;
use App\Domain\Finance\Enums\PaidFrom;
use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Models\BankAccount;
use App\Domain\Finance\Models\Cheque;
use App\Domain\Finance\Services\BankBook;
use App\Domain\Finance\Services\DrawerCash;
use App\Domain\Finance\Services\JournalService;
use App\Domain\Purchasing\Enums\SupplierLedgerType;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Models\SupplierPayment;
use App\Domain\Purchasing\Models\SupplierPaymentAllocation;
use App\Domain\Purchasing\Services\SupplierLedger;
use App\Domain\Sales\Support\Money;
use App\Domain\System\Services\DocumentNumber;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The shop pays a supplier: from the drawer, the safe, a bank account or with a cheque.
 *
 * One transaction: the payment is applied to their unpaid goods receipts (oldest first
 * or as chosen), the supplier ledger gets a debit, the money leaves its place (drawer
 * pay out, bank withdrawal, or a cheque in the register) and the journal gets
 * Dr Payable, Cr Drawer / Safe / Bank / Cheques issued. Money not applied to a
 * receipt stays as an advance with the supplier.
 */
class PaySupplierAction
{
    public function __construct(
        private readonly DocumentNumber $numbers,
        private readonly SupplierLedger $ledger,
        private readonly JournalService $journal,
        private readonly BankBook $bankBook,
        private readonly DrawerCash $drawerCash,
    ) {}

    /**
     * allocations: goods_receipt_id => amount; null or empty = oldest first.
     *
     * @param  array{amount: string, date: string, paid_from: string, bank_account_id?: int|string|null, cheque_number?: string|null, cheque_date?: string|null, reference?: string|null, note?: string|null, allocations?: array<int|string, string|null>|null}  $data
     */
    public function handle(Supplier $supplier, array $data, User $user): SupplierPayment
    {
        $amount = Money::of($data['amount']);
        $from = PaidFrom::tryFrom($data['paid_from']);
        $date = Carbon::parse($data['date']);

        if (! $amount->isPositive()) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount paid.']);
        }

        if ($from === null) {
            throw ValidationException::withMessages(['paid_from' => 'Choose how the supplier was paid.']);
        }

        $bank = null;

        if (in_array($from, [PaidFrom::Bank, PaidFrom::Cheque], true)) {
            $bank = BankAccount::query()->active()->find((int) ($data['bank_account_id'] ?? 0));

            if ($bank === null) {
                throw ValidationException::withMessages(['bank_account_id' => $from === PaidFrom::Cheque ? 'Choose the account the cheque is drawn on.' : 'Choose the bank account.']);
            }
        }

        if ($from === PaidFrom::Cheque && trim((string) ($data['cheque_number'] ?? '')) === '') {
            throw ValidationException::withMessages(['cheque_number' => 'Enter the cheque number.']);
        }

        return DB::transaction(function () use ($supplier, $data, $user, $amount, $from, $date, $bank): SupplierPayment {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            $open = $supplier->unpaidReceipts()->lockForUpdate()->get();
            $allocations = ($data['allocations'] ?? null) !== null && $data['allocations'] !== []
                ? $this->manualAllocations($open, $data['allocations'], $amount)
                : $this->oldestFirst($open, $amount);

            $this->numbers->ensure('SPAY', 'SP-{Y}-', 5);

            $payment = SupplierPayment::create([
                'number' => $this->numbers->next('SPAY'),
                'supplier_id' => $supplier->id,
                'date' => $date,
                'amount' => (string) $amount,
                'paid_from' => $from,
                'bank_account_id' => $bank?->id,
                'reference' => filled($data['reference'] ?? null) ? mb_substr((string) $data['reference'], 0, 100) : null,
                'note' => filled($data['note'] ?? null) ? mb_substr((string) $data['note'], 0, 255) : null,
                'created_by' => $user->id,
            ]);

            foreach ($allocations as $receiptId => $allocated) {
                SupplierPaymentAllocation::create(['supplier_payment_id' => $payment->id, 'goods_receipt_id' => $receiptId, 'amount' => (string) $allocated]);
                $receipt = $open->firstWhere('id', $receiptId);
                $receipt?->forceFill(['amount_paid' => (string) Money::of($receipt->amount_paid)->plus($allocated)])->save();
            }

            $description = "Payment {$payment->number} to {$supplier->name}";
            $credit = match ($from) {
                PaidFrom::CashDrawer => $this->fromDrawer($payment, $amount, $description, $user),
                PaidFrom::Safe => SystemAccount::Safe,
                PaidFrom::Bank => $this->fromBank($payment, $bank, $amount, $date, $description, $user),
                PaidFrom::Cheque => $this->issueCheque($payment, $supplier, $bank, $amount, $data, $user),
            };

            $this->ledger->debit($supplier->id, SupplierLedgerType::Payment, $payment, $amount, $date, $user->id, $from->label().($payment->reference ? " {$payment->reference}" : ''));

            $this->journal->post($description, $date, [
                ['account' => SystemAccount::AccountsPayable, 'debit' => $amount],
                ['account' => $credit, 'credit' => $amount],
            ], $payment, 'supplier_payment.made', $user->id);

            return $payment;
        });
    }

    private function fromDrawer(SupplierPayment $payment, BigDecimal $amount, string $description, User $user): SystemAccount
    {
        $movement = $this->drawerCash->takeOut($amount, CashMovementType::PayOut, $description, $payment, $user->id);
        $payment->forceFill(['drawer_session_id' => $movement->drawer_session_id])->save();

        return SystemAccount::CashDrawer;
    }

    private function fromBank(SupplierPayment $payment, ?BankAccount $bank, BigDecimal $amount, Carbon $date, string $description, User $user): int
    {
        /** @var BankAccount $bank */
        $this->bankBook->record($bank, BankTransactionType::Withdrawal, $amount, $date, $description, $payment->reference, $payment, $user->id);

        return $bank->account_id;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function issueCheque(SupplierPayment $payment, Supplier $supplier, ?BankAccount $bank, BigDecimal $amount, array $data, User $user): SystemAccount
    {
        $cheque = new Cheque([
            'direction' => ChequeDirection::Issued,
            'number' => mb_substr(trim((string) $data['cheque_number']), 0, 30),
            'bank_name' => $bank?->bank_name,
            'branch' => $bank?->branch,
            'cheque_date' => filled($data['cheque_date'] ?? null) ? Carbon::parse((string) $data['cheque_date']) : $payment->date,
            'amount' => (string) $amount,
            'party_type' => $supplier->getMorphClass(),
            'party_id' => $supplier->id,
            'bank_account_id' => $bank?->id,
            'source_type' => $payment->getMorphClass(),
            'source_id' => $payment->id,
            'created_by' => $user->id,
        ]);
        $cheque->transition(ChequeStatus::Pending, $user->id, "Payment {$payment->number}");
        $cheque->save();

        $payment->forceFill(['cheque_id' => $cheque->id, 'reference' => $payment->reference ?? "Cheque {$cheque->number}"])->save();

        return SystemAccount::ChequesIssued;
    }

    /**
     * @param  Collection<int, GoodsReceipt>  $open
     * @return array<int, BigDecimal>
     */
    private function oldestFirst(Collection $open, BigDecimal $amount): array
    {
        $left = $amount;
        $allocations = [];

        foreach ($open as $receipt) {
            if (! $left->isPositive()) {
                break;
            }

            $outstanding = $receipt->outstanding();
            $part = $outstanding->isLessThan($left) ? $outstanding : $left;
            $allocations[$receipt->id] = $part;
            $left = $left->minus($part);
        }

        return $allocations;
    }

    /**
     * @param  Collection<int, GoodsReceipt>  $open
     * @param  array<int|string, string|null>  $requested
     * @return array<int, BigDecimal>
     */
    private function manualAllocations(Collection $open, array $requested, BigDecimal $amount): array
    {
        $allocations = [];
        $total = Money::zero();

        foreach ($requested as $receiptId => $value) {
            $part = Money::of($value);

            if ($part->isZero()) {
                continue;
            }

            $receipt = $open->firstWhere('id', (int) $receiptId);

            if ($receipt === null) {
                throw ValidationException::withMessages(['allocations' => 'One of the goods receipts is not an unpaid receipt of this supplier.']);
            }

            if ($part->isNegative() || $part->isGreaterThan($receipt->outstanding())) {
                throw ValidationException::withMessages(["allocations.{$receipt->id}" => "{$receipt->number}: pay between 0 and Rs. ".Money::format($receipt->outstanding()).'.']);
            }

            $allocations[$receipt->id] = $part;
            $total = $total->plus($part);
        }

        if ($total->isGreaterThan($amount)) {
            throw ValidationException::withMessages(['allocations' => 'The amounts for the goods receipts add up to more than the payment.']);
        }

        return $allocations;
    }
}
