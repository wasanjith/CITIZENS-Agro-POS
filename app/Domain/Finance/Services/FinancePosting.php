<?php

namespace App\Domain\Finance\Services;

use App\Domain\CashDrawer\Enums\CashMovementType;
use App\Domain\CashDrawer\Models\CashMovement;
use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\Catalog\Models\OpeningStockEntry;
use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Models\CustomerPayment;
use App\Domain\Finance\Enums\BankTransactionType;
use App\Domain\Finance\Enums\ChequeDirection;
use App\Domain\Finance\Enums\ChequeStatus;
use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\Cheque;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Finance\Models\JournalLine;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Stocktake;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Models\SupplierReturn;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\RefundMethod;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleReturn;
use App\Domain\Sales\Models\SaleReturnLine;
use App\Domain\Sales\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The posting rules (IMPLEMENTATION_PLAN section 14): turns what happened in the shop
 * into journal entries, and card / cheque money into bank transactions and cheques.
 *
 * Called by the actions of the other modules inside their own transaction, so a sale
 * can never exist without its journal entry. Each method posts once per document and
 * event, so the backfill command can call them again safely.
 */
class FinancePosting
{
    public const SALE_SETTLED = 'sale.settled';

    public const SALE_VOIDED = 'sale.voided';

    public function __construct(
        private readonly JournalService $journal,
        private readonly BankBook $bankBook,
    ) {}

    /**
     * Cash / card / cheque / credit sale settled at the main cashier:
     * Dr money account, Cr Sales + Tax payable; Dr COGS, Cr Inventory.
     */
    public function saleSettled(Sale $sale, Payment $payment, ?int $userId = null): void
    {
        if ($this->journal->hasPosted($sale, self::SALE_SETTLED)) {
            return;
        }

        $date = $sale->settled_at ?? now();
        $total = Money::of($sale->total);
        $tax = Money::of($sale->tax_total);
        $cost = Money::of($sale->cost_total);
        $moneyAccount = $this->receiveMoney($payment->method, $total, $date, $sale, $payment->reference, $sale->customer_id, $userId, $payment);

        $this->journal->post("Sale {$sale->invoice_no} ({$payment->method->label()})", $date, [
            ['account' => $moneyAccount, 'debit' => $total],
            ['account' => SystemAccount::SalesRevenue, 'credit' => $total->minus($tax)],
            ['account' => SystemAccount::TaxPayable, 'credit' => $tax],
            ['account' => SystemAccount::CostOfGoodsSold, 'debit' => $cost, 'memo' => 'Cost of the goods sold'],
            ['account' => SystemAccount::Inventory, 'credit' => $cost],
        ], $sale, self::SALE_SETTLED, $userId);
    }

    /**
     * A settled sale voided the same day: the settlement entry is reversed, a card
     * deposit is taken back out of the bank and a cheque still in hand is cancelled.
     */
    public function saleVoided(Sale $sale, ?int $userId = null, ?\DateTimeInterface $date = null): void
    {
        $date ??= today();
        $settled = $this->journal->find($sale, self::SALE_SETTLED);

        if ($settled === null || $this->journal->hasPosted($sale, self::SALE_VOIDED)) {
            return;
        }

        $payment = $sale->payments()->where('amount', '>', 0)->oldest('id')->first();

        if ($payment?->cheque_id !== null) {
            $cheque = Cheque::query()->lockForUpdate()->findOrFail($payment->cheque_id);

            if ($cheque->status !== ChequeStatus::Pending) {
                throw ValidationException::withMessages(['sale' => "The cheque for {$sale->invoice_no} is already {$cheque->status->label()}. Use a sale return instead."]);
            }

            $cheque->transition(ChequeStatus::Cancelled, $userId, "Void of {$sale->invoice_no}");
            $cheque->save();
        }

        if ($payment?->bank_account_id !== null) {
            $bank = $payment->bankAccount()->firstOrFail();
            $this->bankBook->record($bank, BankTransactionType::Withdrawal, $payment->amount, $date, "Void of {$sale->invoice_no}", $payment->reference, $sale, $userId);
        }

        $this->journal->reverse($settled, $date, "Void of sale {$sale->invoice_no}", $userId, $sale, self::SALE_VOIDED);
    }

    /**
     * Goods came back: Dr Sales returns (+ tax), Cr Cash drawer / Receivable;
     * restocked goods Dr Inventory, Cr COGS; damaged goods then Dr Stock losses, Cr Inventory.
     */
    public function saleReturned(SaleReturn $return, ?int $userId = null): void
    {
        if ($this->journal->hasPosted($return, 'sale.returned')) {
            return;
        }

        $sale = $return->sale()->firstOrFail();
        $amount = Money::of($return->total);
        $tax = Money::of($sale->total)->isPositive()
            ? $amount->multipliedBy($sale->tax_total)->dividedBy($sale->total, 2, RoundingMode::HalfUp)
            : Money::zero();
        $cost = Money::of($return->cost_total);
        $damaged = $return->lines()->where('restock', false)->get()
            ->reduce(fn (BigDecimal $sum, SaleReturnLine $line) => $sum->plus($line->cost_total), Money::zero());

        $this->journal->post("Return {$return->number} on {$sale->invoice_no}", $return->created_at ?? now(), [
            ['account' => SystemAccount::SalesReturns, 'debit' => $amount->minus($tax)],
            ['account' => SystemAccount::TaxPayable, 'debit' => $tax],
            ['account' => $return->refund_method === RefundMethod::Cash ? SystemAccount::CashDrawer : SystemAccount::AccountsReceivable, 'credit' => $amount],
            ['account' => SystemAccount::Inventory, 'debit' => $cost, 'memo' => 'Goods back in stock'],
            ['account' => SystemAccount::CostOfGoodsSold, 'credit' => $cost],
            ['account' => SystemAccount::InventoryLoss, 'debit' => $damaged, 'memo' => 'Damaged, not restocked'],
            ['account' => SystemAccount::Inventory, 'credit' => $damaged],
        ], $return, 'sale.returned', $userId);
    }

    /**
     * A customer paid towards their account: Dr money account, Cr Receivable.
     *
     * @param  array{bank_name?: string|null, branch?: string|null, cheque_date?: string|null}  $cheque
     */
    public function customerPaymentReceived(CustomerPayment $payment, array $cheque = [], ?int $userId = null): void
    {
        if ($this->journal->hasPosted($payment, 'customer_payment.received')) {
            return;
        }

        $amount = Money::of($payment->amount);
        $date = $payment->created_at ?? now();
        $moneyAccount = $this->receiveMoney($payment->method, $amount, $date, $payment, $payment->reference, $payment->customer_id, $userId, $payment, $cheque);

        $this->journal->post("Customer payment {$payment->number}", $date, [
            ['account' => $moneyAccount, 'debit' => $amount],
            ['account' => SystemAccount::AccountsReceivable, 'credit' => $amount],
        ], $payment, 'customer_payment.received', $userId);
    }

    /**
     * What a customer owed from the old books: Dr Receivable, Cr Opening balances.
     */
    public function customerOpening(Customer $customer, BigDecimal|string $amount, ?int $userId = null): void
    {
        if ($this->journal->hasPosted($customer, 'customer.opening')) {
            return;
        }

        $this->journal->post("Opening balance of customer {$customer->code} {$customer->name}", $customer->created_at ?? now(), [
            ['account' => SystemAccount::AccountsReceivable, 'debit' => $amount],
            ['account' => SystemAccount::OpeningBalanceEquity, 'credit' => $amount],
        ], $customer, 'customer.opening', $userId);
    }

    /**
     * What the shop owed a supplier when the system went live: Dr Opening balances,
     * Cr Payable. Editing the opening balance later posts the difference.
     */
    public function supplierOpening(Supplier $supplier, ?int $userId = null): void
    {
        $posted = JournalLine::query()
            ->whereHas('entry', fn ($query) => $query->where('source_type', $supplier->getMorphClass())->where('source_id', $supplier->id)->where('event', 'supplier.opening'))
            ->where('account_id', app(ChartOfAccounts::class)->id(SystemAccount::AccountsPayable))
            ->selectRaw('COALESCE(SUM(credit - debit), 0) AS total')
            ->toBase()
            ->value('total');

        $difference = Money::of($supplier->opening_balance)->minus(Money::of((string) $posted));

        $this->journal->post("Opening balance of supplier {$supplier->name}", now(), [
            ['account' => SystemAccount::OpeningBalanceEquity, 'debit' => $difference],
            ['account' => SystemAccount::AccountsPayable, 'credit' => $difference],
        ], $supplier, 'supplier.opening', $userId);
    }

    /**
     * Goods received: Dr Inventory (+ input tax), Cr Payable.
     */
    public function goodsReceived(GoodsReceipt $receipt, ?int $userId = null): void
    {
        if ($this->journal->hasPosted($receipt, 'grn.posted')) {
            return;
        }

        $total = Money::of($receipt->total);
        $tax = Money::of($receipt->tax);

        $this->journal->post("Goods received {$receipt->number}", $receipt->received_at, [
            ['account' => SystemAccount::Inventory, 'debit' => $total->minus($tax)],
            ['account' => SystemAccount::TaxPayable, 'debit' => $tax, 'memo' => 'Tax on the supplier invoice'],
            ['account' => SystemAccount::AccountsPayable, 'credit' => $total],
        ], $receipt, 'grn.posted', $userId);
    }

    /**
     * Goods sent back to a supplier: Dr Payable at the buying price, Cr Inventory at the
     * stock cost; a difference between the two is a stock gain or loss.
     */
    public function supplierReturned(SupplierReturn $return, ?int $userId = null): void
    {
        if ($this->journal->hasPosted($return, 'supplier_return.posted')) {
            return;
        }

        $total = Money::of($return->total);
        $stockCost = $this->movementValues($return)['out'];
        $difference = $total->minus($stockCost);

        $this->journal->post("Return to supplier {$return->number}", $return->return_date, [
            ['account' => SystemAccount::AccountsPayable, 'debit' => $total],
            ['account' => SystemAccount::Inventory, 'credit' => $stockCost],
            ['account' => SystemAccount::InventoryGain, 'credit' => $difference->isPositive() ? $difference : '0', 'memo' => 'Returned above stock cost'],
            ['account' => SystemAccount::InventoryLoss, 'debit' => $difference->isNegative() ? $difference->negated() : '0', 'memo' => 'Returned below stock cost'],
        ], $return, 'supplier_return.posted', $userId);
    }

    /**
     * Approved adjustment or posted stocktake, valued at batch cost:
     * gains Dr Inventory, Cr Stock gains; losses Dr Stock losses, Cr Inventory.
     */
    public function stockCorrected(StockAdjustment|Stocktake $document, ?int $userId = null): void
    {
        $event = $document instanceof Stocktake ? 'stocktake.posted' : 'adjustment.approved';

        if ($this->journal->hasPosted($document, $event)) {
            return;
        }

        ['in' => $gain, 'out' => $loss] = $this->movementValues($document);
        $date = ($document instanceof Stocktake ? $document->posted_at : $document->approved_at) ?? now();

        $this->journal->post(($document instanceof Stocktake ? 'Stocktake ' : 'Stock adjustment ').$document->referenceLabel(), $date, [
            ['account' => SystemAccount::Inventory, 'debit' => $gain],
            ['account' => SystemAccount::InventoryGain, 'credit' => $gain],
            ['account' => SystemAccount::InventoryLoss, 'debit' => $loss],
            ['account' => SystemAccount::Inventory, 'credit' => $loss],
        ], $document, $event, $userId);
    }

    /**
     * Opening stock from the product import: Dr Inventory, Cr Opening balances.
     */
    public function openingStockPosted(OpeningStockEntry $entry, ?int $userId = null): void
    {
        if ($this->journal->hasPosted($entry, 'opening_stock.posted')) {
            return;
        }

        $value = $this->movementValues($entry)['in'];

        $this->journal->post("Opening stock {$entry->referenceLabel()}", $entry->posted_at ?? now(), [
            ['account' => SystemAccount::Inventory, 'debit' => $value],
            ['account' => SystemAccount::OpeningBalanceEquity, 'credit' => $value],
        ], $entry, 'opening_stock.posted', $userId);
    }

    /**
     * The morning float is brought from home: Dr Cash drawer, Cr Cash at home (account "safe").
     */
    public function drawerOpened(DrawerSession $session, ?int $userId = null): void
    {
        if ($this->journal->hasPosted($session, 'drawer.opened')) {
            return;
        }

        $this->journal->post("Drawer session #{$session->id} opened (float)", $session->opened_at, [
            ['account' => SystemAccount::CashDrawer, 'debit' => $session->opening_float],
            ['account' => SystemAccount::Safe, 'credit' => $session->opening_float],
        ], $session, 'drawer.opened', $userId ?? $session->holder_user_id);
    }

    /**
     * Drawer counted: a shortage is an expense, an excess income; the counted cash then
     * goes home with the owner (or becomes the next holder's float after a handover).
     */
    public function drawerClosed(DrawerSession $session, ?int $userId = null): void
    {
        if ($session->closed_at === null || $this->journal->hasPosted($session, 'drawer.closed')) {
            return;
        }

        $variance = Money::of($session->variance);
        $counted = Money::of($session->counted_cash);

        $this->journal->post("Drawer session #{$session->id} closed", $session->closed_at, [
            ['account' => SystemAccount::CashShort, 'debit' => $variance->isNegative() ? $variance->negated() : '0', 'memo' => 'Drawer short'],
            ['account' => SystemAccount::CashOver, 'credit' => $variance->isPositive() ? $variance : '0', 'memo' => 'Drawer over'],
            ['account' => SystemAccount::CashDrawer, 'debit' => $variance],
            ['account' => SystemAccount::Safe, 'debit' => $counted, 'memo' => 'Counted cash out of the drawer'],
            ['account' => SystemAccount::CashDrawer, 'credit' => $counted],
        ], $session, 'drawer.closed', $userId ?? $session->closed_by);
    }

    /**
     * Pay in (change brought from home), pay out (cash the owner takes) and safe drop (cash sent home).
     * Movements made for an expense, a supplier payment or a bank deposit are posted
     * by that document instead.
     */
    public function cashMovement(CashMovement $movement, ?int $userId = null): void
    {
        if ($movement->reference_type !== null || $this->journal->hasPosted($movement, 'cash.moved')) {
            return;
        }

        $other = match ($movement->type) {
            CashMovementType::PayIn => SystemAccount::Safe,
            CashMovementType::PayOut => SystemAccount::OwnerDrawings,
            CashMovementType::SafeDrop => SystemAccount::Safe,
            CashMovementType::BankDeposit => null,
        };

        if ($other === null) {
            return;
        }

        $amount = Money::of($movement->amount);
        $drawer = $movement->type->sign() > 0 ? $amount : $amount->negated();

        $this->journal->post("{$movement->type->label()}: {$movement->reason}", $movement->created_at ?? now(), [
            ['account' => SystemAccount::CashDrawer, 'debit' => $drawer],
            ['account' => $other, 'credit' => $drawer],
        ], $movement, 'cash.moved', $userId ?? $movement->user_id);
    }

    /**
     * The account money received by $method lands in: the drawer, the card/transfer bank
     * account (with a bank deposit), cheques in hand (with a cheque in the register) or
     * the receivable.
     *
     * @param  array{bank_name?: string|null, branch?: string|null, cheque_date?: string|null}  $chequeDetails
     */
    private function receiveMoney(PaymentMethod $method, BigDecimal $amount, \DateTimeInterface $date, Model $document, ?string $reference, ?int $customerId, ?int $userId, Payment|CustomerPayment $payment, array $chequeDetails = []): SystemAccount|Account
    {
        $label = $document instanceof Sale ? "Sale {$document->invoice_no}" : 'Payment '.($document->getAttribute('number') ?? '');

        switch ($method) {
            case PaymentMethod::Card:
            case PaymentMethod::BankTransfer:
                $bank = $this->bankBook->receivingAccount();

                if ($bank === null) {
                    return SystemAccount::CardClearing;
                }

                $this->bankBook->record($bank, BankTransactionType::Deposit, $amount, $date, "{$method->label()}: {$label}", $reference, $document, $userId);
                $payment->forceFill(['bank_account_id' => $bank->id])->save();

                return $bank->account()->firstOrFail();

            case PaymentMethod::Cheque:
                $customer = $customerId !== null ? Customer::query()->withTrashed()->find($customerId) : null;
                $chequeDate = filled($chequeDetails['cheque_date'] ?? null) ? Carbon::parse((string) $chequeDetails['cheque_date']) : Carbon::parse($date)->startOfDay();

                $cheque = new Cheque([
                    'direction' => ChequeDirection::Received,
                    'number' => mb_substr((string) ($reference ?? '—'), 0, 30),
                    'bank_name' => $chequeDetails['bank_name'] ?? null,
                    'branch' => $chequeDetails['branch'] ?? null,
                    'cheque_date' => $chequeDate,
                    'amount' => (string) $amount,
                    'party_type' => $customer?->getMorphClass(),
                    'party_id' => $customer?->id,
                    'source_type' => $document->getMorphClass(),
                    'source_id' => $document->getKey(),
                    'created_by' => $userId,
                ]);
                $cheque->transition(ChequeStatus::Pending, $userId, $label);
                $cheque->save();
                $payment->forceFill(['cheque_id' => $cheque->id])->save();

                return SystemAccount::ChequesInHand;

            case PaymentMethod::Credit:
                return SystemAccount::AccountsReceivable;

            default:
                return SystemAccount::CashDrawer;
        }
    }

    /**
     * Value of the stock a document moved in and out, at batch cost.
     *
     * @return array{in: BigDecimal, out: BigDecimal}
     */
    private function movementValues(Model $document): array
    {
        $row = StockMovement::query()
            ->where('reference_type', $document->getMorphClass())
            ->where('reference_id', $document->getKey())
            ->selectRaw('COALESCE(SUM(CASE WHEN qty > 0 THEN qty * unit_cost ELSE 0 END), 0) AS in_value')
            ->selectRaw('COALESCE(SUM(CASE WHEN qty < 0 THEN -qty * unit_cost ELSE 0 END), 0) AS out_value')
            ->toBase()
            ->first();

        return [
            'in' => BigDecimal::of((string) ($row->in_value ?? '0'))->toScale(2, RoundingMode::HalfUp),
            'out' => BigDecimal::of((string) ($row->out_value ?? '0'))->toScale(2, RoundingMode::HalfUp),
        ];
    }

    /**
     * Entries a document posted (for its page).
     *
     * @return Collection<int, JournalEntry>
     */
    public function entriesFor(Model $document): Collection
    {
        return JournalEntry::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->with('lines.account')
            ->orderBy('id')
            ->get();
    }
}
