<?php

namespace App\Domain\Sales\Actions;

use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customers\Enums\CustomerLedgerType;
use App\Domain\Customers\Services\CustomerLedger;
use App\Domain\Finance\Services\FinancePosting;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Inventory\Support\Qty;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\PrintDocumentType;
use App\Domain\Sales\Enums\RefundMethod;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\PrintJob;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Sales\Models\SaleReturn;
use App\Domain\Sales\Models\SaleReturnLine;
use App\Domain\Sales\Models\SaleReturnLineBatch;
use App\Domain\Sales\Services\ReturnableItems;
use App\Domain\Sales\Support\Money;
use App\Domain\System\Services\DocumentNumber;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Goods come back against a settled invoice, at the main cashier.
 *
 * One transaction: check each quantity against what was sold minus what came back
 * before, put the stock back into the batches it was sold from (damaged goods are then
 * written off as DAMAGE), pay the money back (cash from the drawer, or a credit on the
 * customer's account that first lowers what is owed on this invoice) and mark the sale
 * partly or fully returned. The return receipt prints on the main printer.
 */
class CreateSaleReturnAction
{
    public function __construct(
        private readonly StockService $stock,
        private readonly ReturnableItems $returnable,
        private readonly DocumentNumber $numbers,
        private readonly CustomerLedger $ledger,
        private readonly FinancePosting $finance,
    ) {}

    /**
     * lines: sale_item_id => [qty in the sale unit, restock].
     *
     * @param  array{reason: string, refund_method: string, lines: array<int|string, array{qty?: string|null, restock?: bool|null}>}  $data
     * @return array{return: SaleReturn, print_job: PrintJob|null, created: bool}
     */
    public function handle(Sale $sale, User $cashier, Terminal $terminal, DrawerSession $session, array $data, string $idempotencyKey): array
    {
        $existing = SaleReturn::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return ['return' => $existing, 'print_job' => null, 'created' => false];
        }

        $reason = trim($data['reason']);
        $method = RefundMethod::tryFrom($data['refund_method']);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Enter the reason for the return.']);
        }

        if ($method === null) {
            throw ValidationException::withMessages(['refund_method' => 'Choose how the money is paid back.']);
        }

        try {
            [$return, $printJob] = DB::transaction(fn () => $this->create($sale, $cashier, $terminal, $session, $data['lines'], $reason, $method, $idempotencyKey));
        } catch (UniqueConstraintViolationException $exception) {
            $return = SaleReturn::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($return === null) {
                throw $exception;
            }

            return ['return' => $return, 'print_job' => null, 'created' => false];
        }

        return ['return' => $return, 'print_job' => $printJob, 'created' => true];
    }

    /**
     * @param  array<int|string, array{qty?: string|null, restock?: bool|null}>  $requested
     * @return array{0: SaleReturn, 1: PrintJob}
     */
    private function create(Sale $sale, User $cashier, Terminal $terminal, DrawerSession $session, array $requested, string $reason, RefundMethod $method, string $idempotencyKey): array
    {
        $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);

        if (! $sale->canBeReturned()) {
            throw ValidationException::withMessages(['sale' => "{$sale->invoice_no} is {$sale->status->label()}; nothing can be returned against it."]);
        }

        $session = DrawerSession::query()->lockForUpdate()->findOrFail($session->id);

        if (! $session->isOpen() || $session->holder_user_id !== $cashier->id || $session->terminal_id !== $terminal->id) {
            throw ValidationException::withMessages(['drawer' => 'Open your drawer on this terminal before taking returns.']);
        }

        if ($method === RefundMethod::Account && $sale->customer_id === null) {
            throw ValidationException::withMessages(['refund_method' => 'This invoice has no customer account. Refund in cash.']);
        }

        if ($method === RefundMethod::Cash && $sale->isCreditSale() && Money::of($sale->balance_due)->isPositive()) {
            throw ValidationException::withMessages(['refund_method' => "{$sale->invoice_no} is not fully paid yet. The return goes to the customer's account."]);
        }

        $rows = $this->returnable->forSale($sale);
        $lines = $this->validatedLines($rows, $requested);

        $return = SaleReturn::create([
            'number' => $this->numbers->next('RET'),
            'sale_id' => $sale->id,
            'customer_id' => $sale->customer_id,
            'reason' => mb_substr($reason, 0, 255),
            'refund_method' => $method,
            'total' => '0.00',
            'terminal_id' => $terminal->id,
            'drawer_session_id' => $session->id,
            'created_by' => $cashier->id,
            'approved_by' => $cashier->id,
            'idempotency_key' => $idempotencyKey,
        ]);

        $products = Product::query()->whereIn('id', array_map(fn (array $line) => $line['item']->product_id, $lines))->get()->keyBy('id');
        $total = Money::zero();
        $costTotal = Money::zero();

        foreach ($lines as $line) {
            /** @var SaleItem $item */
            $item = $line['item'];
            $returnLine = SaleReturnLine::create([
                'sale_return_id' => $return->id,
                'sale_item_id' => $item->id,
                'qty' => (string) $line['qty'],
                'base_qty' => (string) $line['base_qty'],
                'amount' => (string) $line['amount'],
                'restock' => $line['restock'],
            ]);

            $lineCost = Money::zero();

            foreach ($line['batches'] as $batchId => $batchLine) {
                $batch = Batch::query()->findOrFail($batchId);
                $product = $products[$item->product_id];
                $this->stock->adjust($product, $item->variant_id, $batch, (string) $batchLine['qty'], MovementType::SaleReturn, $return, $cashier->id, "Return of {$sale->invoice_no}: {$reason}");

                if (! $line['restock']) {
                    $this->stock->adjust($product, $item->variant_id, $batch, (string) $batchLine['qty']->negated(), MovementType::Damage, $return, $cashier->id, "Damaged, returned on {$sale->invoice_no}: {$reason}");
                }

                SaleReturnLineBatch::create([
                    'sale_return_line_id' => $returnLine->id,
                    'batch_id' => $batchId,
                    'base_qty' => (string) $batchLine['qty'],
                    'unit_cost' => $batchLine['unit_cost'],
                ]);
                $lineCost = $lineCost->plus($batchLine['qty']->multipliedBy($batchLine['unit_cost']));
            }

            $lineCost = $lineCost->toScale(2, RoundingMode::HalfUp);
            $returnLine->forceFill(['cost_total' => (string) $lineCost])->save();
            $total = $total->plus($line['amount']);
            $costTotal = $costTotal->plus($lineCost);
        }

        $return->forceFill(['total' => (string) $total, 'cost_total' => (string) $costTotal])->save();

        $this->refund($sale, $return, $method, $total, $cashier, $session);
        $this->finance->saleReturned($return, $cashier->id);

        $fullyReturned = collect($rows)->every(function (array $row) use ($lines): bool {
            $now = isset($lines[$row['item']->id]) ? $lines[$row['item']->id]['base_qty'] : Qty::of('0');

            return $row['returnable_base']->minus($now)->isZero();
        });

        $sale->forceFill(['status' => $fullyReturned ? SaleStatus::Returned : SaleStatus::PartiallyReturned])->save();

        return [$return, PrintJob::record(PrintDocumentType::SaleReturn, $return->id, $terminal->loadMissing('printer'), $cashier->id)];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows  from ReturnableItems::forSale
     * @param  array<int|string, array{qty?: string|null, restock?: bool|null}>  $requested
     * @return array<int, array{item: SaleItem, qty: BigDecimal, base_qty: BigDecimal, amount: BigDecimal, restock: bool, batches: array<int, array{qty: BigDecimal, unit_cost: string}>}>
     */
    private function validatedLines(array $rows, array $requested): array
    {
        $lines = [];

        foreach ($requested as $itemId => $input) {
            $qtyText = trim((string) ($input['qty'] ?? ''));

            if ($qtyText === '' || (is_numeric($qtyText) && Qty::of($qtyText)->isZero())) {
                continue;
            }

            $row = $rows[(int) $itemId] ?? null;

            if ($row === null) {
                throw ValidationException::withMessages(['lines' => 'A line does not belong to this invoice.']);
            }

            /** @var SaleItem $item */
            $item = $row['item'];
            $field = "lines.{$item->id}.qty";

            if (! is_numeric($qtyText) || Qty::of($qtyText)->isNegative()) {
                throw ValidationException::withMessages([$field => "Enter the quantity of {$item->name_snapshot} that came back."]);
            }

            $qty = Qty::of($qtyText);
            $baseQty = $qty->multipliedBy($item->factor)->toScale(Qty::SCALE, RoundingMode::HalfUp);

            if ($baseQty->isGreaterThan($row['returnable_base'])) {
                throw ValidationException::withMessages([$field => "{$item->name_snapshot}: at most ".Qty::format((string) $row['returnable_qty'])." {$item->unit_snapshot} can come back (sold ".Qty::format($item->qty).', returned before '.Qty::format((string) $row['returned_qty']).').']);
            }

            // Everything still here comes back: refund exactly what is left, so a full
            // return adds up to the invoice total to the cent.
            $amount = $baseQty->isEqualTo($row['returnable_base'])
                ? $row['refundable']
                : Money::of($row['net']->multipliedBy($baseQty)->dividedBy($item->base_qty, 2, RoundingMode::HalfUp));

            if ($amount->isGreaterThan($row['refundable'])) {
                $amount = $row['refundable'];
            }

            $lines[$item->id] = [
                'item' => $item,
                'qty' => $qty,
                'base_qty' => $baseQty,
                'amount' => $amount,
                'restock' => (bool) ($input['restock'] ?? true),
                'batches' => $this->batchesFor($row['batches'], $baseQty),
            ];
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Enter the quantity that came back for at least one item.']);
        }

        return $lines;
    }

    /**
     * Spread the returned quantity over the batches the line was sold from (in the order
     * they were issued), skipping what earlier returns already put back.
     *
     * @param  list<array{batch_id: int, remaining: BigDecimal, unit_cost: string}>  $batches
     * @return array<int, array{qty: BigDecimal, unit_cost: string}>
     */
    private function batchesFor(array $batches, BigDecimal $baseQty): array
    {
        $left = $baseQty;
        $result = [];

        foreach ($batches as $batch) {
            if (! $left->isPositive()) {
                break;
            }

            $part = $batch['remaining']->isLessThan($left) ? $batch['remaining'] : $left;

            if ($part->isPositive()) {
                $result[$batch['batch_id']] = ['qty' => $part, 'unit_cost' => $batch['unit_cost']];
                $left = $left->minus($part);
            }
        }

        return $result;
    }

    private function refund(Sale $sale, SaleReturn $return, RefundMethod $method, BigDecimal $total, User $cashier, DrawerSession $session): void
    {
        if ($method === RefundMethod::Cash) {
            Payment::create([
                'sale_id' => $sale->id,
                'method' => PaymentMethod::Cash,
                'amount' => (string) $total->negated(),
                'reference' => $return->number,
                'recorded_by' => $cashier->id,
                'confirmed_by' => $cashier->id,
                'drawer_session_id' => $session->id,
            ]);

            return;
        }

        $this->ledger->credit((int) $sale->customer_id, CustomerLedgerType::Return, $return, $total, today(), $cashier->id, "Return on {$sale->invoice_no}");

        $owed = Money::of($sale->balance_due);

        if ($owed->isPositive()) {
            $sale->forceFill(['balance_due' => (string) ($owed->isGreaterThan($total) ? $owed->minus($total) : Money::zero())])->save();
        }
    }
}
