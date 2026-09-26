<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Customers\Enums\CustomerLedgerType;
use App\Domain\Customers\Models\CustomerPayment;
use App\Domain\Customers\Services\CustomerLedger;
use App\Domain\Finance\Enums\BankTransactionType;
use App\Domain\Finance\Enums\ChequeDirection;
use App\Domain\Finance\Enums\ChequeStatus;
use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\Cheque;
use App\Domain\Finance\Services\BankBook;
use App\Domain\Finance\Services\JournalService;
use App\Domain\Purchasing\Enums\SupplierLedgerType;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\SupplierPayment;
use App\Domain\Purchasing\Services\SupplierLedger;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A cheque did not pay.
 *
 *  - Received cheque bounced: the customer owes the money again. For a customer payment
 *    the invoices it paid are open again; for a sale paid by cheque the invoice is.
 *    Dr Receivable (or Dishonoured cheques for a walk-in), Cr Cheques in hand (or the
 *    bank, when it had already cleared).
 *  - Issued cheque bounced or cancelled: the shop owes the supplier again and the
 *    goods receipts it paid are unpaid again. Dr Cheques issued, Cr Payable.
 */
class ReturnChequeAction
{
    public function __construct(
        private readonly JournalService $journal,
        private readonly BankBook $bankBook,
        private readonly CustomerLedger $customerLedger,
        private readonly SupplierLedger $supplierLedger,
    ) {}

    public function handle(Cheque $cheque, Carbon $date, User $user, string $reason, bool $cancel = false): Cheque
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Enter the reason.']);
        }

        return DB::transaction(function () use ($cheque, $date, $user, $reason, $cancel): Cheque {
            $cheque = Cheque::query()->lockForUpdate()->findOrFail($cheque->id);

            if ($cheque->direction === ChequeDirection::Received) {
                if ($cancel || ! in_array($cheque->status, [ChequeStatus::Pending, ChequeStatus::Deposited, ChequeStatus::Cleared], true)) {
                    throw ValidationException::withMessages(['cheque' => "Cheque {$cheque->number} is {$cheque->status->label()}; it cannot be marked as bounced."]);
                }

                $this->bounceReceived($cheque, $date, $user, $reason);
            } else {
                if ($cheque->status !== ChequeStatus::Pending) {
                    throw ValidationException::withMessages(['cheque' => "Cheque {$cheque->number} is {$cheque->status->label()}; only a cheque that has not cleared can be ".($cancel ? 'cancelled.' : 'marked as bounced.')]);
                }

                $this->reverseIssued($cheque, $date, $user, $reason, $cancel);
            }

            $cheque->bounced_on = $date;
            $cheque->transition($cancel ? ChequeStatus::Cancelled : ChequeStatus::Bounced, $user->id, $reason);
            $cheque->save();

            return $cheque;
        });
    }

    private function bounceReceived(Cheque $cheque, Carbon $date, User $user, string $reason): void
    {
        $amount = Money::of($cheque->amount);
        $source = $cheque->source;
        $debit = SystemAccount::DishonouredCheques;

        if ($cheque->status === ChequeStatus::Cleared) {
            $bank = $cheque->bankAccount()->firstOrFail();
            $this->bankBook->record($bank, BankTransactionType::Withdrawal, $amount, $date, "Cheque {$cheque->number} returned: {$reason}", $cheque->number, $cheque, $user->id);
            $credit = $bank->account()->firstOrFail();
        } else {
            $credit = SystemAccount::ChequesInHand;
        }

        if ($source instanceof CustomerPayment) {
            $payment = CustomerPayment::query()->lockForUpdate()->findOrFail($source->id);
            $payment->forceFill(['reversed_at' => now()])->save();

            foreach ($payment->allocations as $allocation) {
                $sale = Sale::query()->lockForUpdate()->findOrFail($allocation->sale_id);
                $sale->forceFill(['balance_due' => (string) Money::of($sale->balance_due)->plus($allocation->amount)])->save();
            }

            $this->customerLedger->debit($payment->customer_id, CustomerLedgerType::ChequeBounced, $payment, $amount, $date, $user->id, $date, "Cheque {$cheque->number}: {$reason}");
            $debit = SystemAccount::AccountsReceivable;
        } elseif ($source instanceof Sale && $source->customer_id !== null) {
            $sale = Sale::query()->lockForUpdate()->findOrFail($source->id);
            $sale->forceFill([
                'balance_due' => (string) Money::of($sale->balance_due)->plus($amount),
                'due_date' => $sale->due_date ?? $date,
            ])->save();

            $this->customerLedger->debit($sale->customer_id, CustomerLedgerType::ChequeBounced, $sale, $amount, $date, $user->id, $date, "Cheque {$cheque->number}: {$reason}");
            $debit = SystemAccount::AccountsReceivable;
        }

        $this->post($cheque, $date, $user, "Cheque {$cheque->number} bounced: {$reason}", $debit, $credit);
    }

    private function reverseIssued(Cheque $cheque, Carbon $date, User $user, string $reason, bool $cancel): void
    {
        $source = $cheque->source;

        if ($source instanceof SupplierPayment) {
            $payment = SupplierPayment::query()->lockForUpdate()->findOrFail($source->id);
            $payment->forceFill(['reversed_at' => now()])->save();

            foreach ($payment->allocations as $allocation) {
                $receipt = GoodsReceipt::query()->lockForUpdate()->findOrFail($allocation->goods_receipt_id);
                $receipt->forceFill(['amount_paid' => (string) Money::of($receipt->amount_paid)->minus($allocation->amount)])->save();
            }

            $this->supplierLedger->credit($payment->supplier_id, SupplierLedgerType::PaymentReversed, $payment, $cheque->amount, $date, $user->id, "Cheque {$cheque->number}: {$reason}");
        }

        $this->post($cheque, $date, $user, "Cheque {$cheque->number} ".($cancel ? 'cancelled' : 'bounced').": {$reason}", SystemAccount::ChequesIssued, SystemAccount::AccountsPayable);
    }

    private function post(Cheque $cheque, Carbon $date, User $user, string $description, SystemAccount|Account $debit, SystemAccount|Account $credit): void
    {
        $this->journal->post($description, $date, [
            ['account' => $debit, 'debit' => $cheque->amount],
            ['account' => $credit, 'credit' => $cheque->amount],
        ], $cheque, 'cheque.returned', $user->id);
    }
}
