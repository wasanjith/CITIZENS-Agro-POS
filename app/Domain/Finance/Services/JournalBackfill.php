<?php

namespace App\Domain\Finance\Services;

use App\Domain\CashDrawer\Models\CashMovement;
use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\Catalog\Models\OpeningStockEntry;
use App\Domain\Customers\Enums\CustomerLedgerType;
use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Models\CustomerLedgerEntry;
use App\Domain\Customers\Models\CustomerPayment;
use App\Domain\Inventory\Enums\AdjustmentStatus;
use App\Domain\Inventory\Enums\StocktakeStatus;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\Stocktake;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Models\SupplierReturn;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleReturn;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Posts the journal for documents created before Finance existed (Phases 2–4), in the
 * order they happened. FinancePosting skips anything already posted, so it can run
 * again at any time.
 */
class JournalBackfill
{
    public function __construct(private readonly FinancePosting $posting) {}

    /**
     * @return array<string, int> documents checked per kind
     */
    public function run(): array
    {
        /** @var list<array{0: CarbonInterface, 1: string, 2: callable(): void}> $jobs */
        $jobs = [];
        $add = function (?CarbonInterface $at, string $kind, callable $post) use (&$jobs): void {
            $jobs[] = [$at ?? now(), $kind, $post];
        };

        foreach (OpeningStockEntry::query()->whereNotNull('posted_at')->get() as $entry) {
            $add($entry->posted_at, 'Opening stock', fn () => $this->posting->openingStockPosted($entry, $entry->created_by));
        }

        $openings = CustomerLedgerEntry::query()->where('type', CustomerLedgerType::Opening)->groupBy('customer_id')->selectRaw('customer_id, SUM(debit - credit) AS amount')->pluck('amount', 'customer_id');

        foreach (Customer::withTrashed()->whereIn('id', $openings->keys())->get() as $customer) {
            $add($customer->created_at, 'Customer openings', fn () => $this->posting->customerOpening($customer, (string) $openings[$customer->id], $customer->created_by));
        }

        foreach (Supplier::withTrashed()->where('opening_balance', '>', 0)->get() as $supplier) {
            $add($supplier->created_at, 'Supplier openings', fn () => $this->posting->supplierOpening($supplier));
        }

        foreach (GoodsReceipt::query()->where('status', GoodsReceiptStatus::Posted)->get() as $receipt) {
            $add($receipt->posted_at ?? $receipt->received_at, 'Goods receipts', fn () => $this->posting->goodsReceived($receipt, $receipt->received_by));
        }

        foreach (SupplierReturn::query()->get() as $return) {
            $add($return->created_at, 'Supplier returns', fn () => $this->posting->supplierReturned($return, $return->created_by));
        }

        foreach (StockAdjustment::query()->where('status', AdjustmentStatus::Approved)->get() as $adjustment) {
            $add($adjustment->approved_at, 'Stock adjustments', fn () => $this->posting->stockCorrected($adjustment, $adjustment->approved_by));
        }

        foreach (Stocktake::query()->where('status', StocktakeStatus::Posted)->get() as $stocktake) {
            $add($stocktake->posted_at, 'Stocktakes', fn () => $this->posting->stockCorrected($stocktake, $stocktake->posted_by));
        }

        foreach (DrawerSession::query()->get() as $session) {
            $add($session->opened_at, 'Drawer sessions', fn () => $this->posting->drawerOpened($session));

            if ($session->closed_at !== null) {
                $add($session->closed_at, 'Drawer closings', fn () => $this->posting->drawerClosed($session));
            }
        }

        foreach (CashMovement::query()->whereNull('reference_type')->get() as $movement) {
            $add($movement->created_at, 'Cash movements', fn () => $this->posting->cashMovement($movement));
        }

        foreach (Sale::query()->whereNotNull('settled_at')->get() as $sale) {
            $payment = $sale->payments()->where('amount', '>', 0)->oldest('id')->first();

            if ($payment === null) {
                continue;
            }

            $add($sale->settled_at, 'Settled sales', fn () => $this->posting->saleSettled($sale, $payment, $sale->settled_by));

            if ($sale->status === SaleStatus::Void) {
                $add($sale->voided_at, 'Voided sales', fn () => $this->posting->saleVoided($sale, $sale->voided_by, $sale->voided_at));
            }
        }

        foreach (SaleReturn::query()->get() as $return) {
            $add($return->created_at, 'Sale returns', fn () => $this->posting->saleReturned($return, $return->created_by));
        }

        foreach (CustomerPayment::query()->get() as $payment) {
            $add($payment->created_at, 'Customer payments', fn () => $this->posting->customerPaymentReceived($payment, [], $payment->received_by));
        }

        usort($jobs, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $counts = [];

        foreach ($jobs as [, $kind, $post]) {
            DB::transaction(function () use ($post): void {
                $post();
            });
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        }

        return $counts;
    }
}
