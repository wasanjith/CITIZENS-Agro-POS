<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Inventory\Support\Qty;
use App\Domain\Purchasing\Enums\SupplierLedgerType;
use App\Domain\Purchasing\Models\SupplierReturn;
use App\Domain\Purchasing\Models\SupplierReturnLine;
use App\Domain\Purchasing\Services\SupplierLedger;
use App\Domain\System\Services\DocumentNumber;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Sends goods back to a supplier from specific batches: stock out at batch cost,
 * supplier debited with the value.
 *
 * Expected $data (already validated):
 *   supplier_id, goods_receipt_id, return_date, reason
 *   lines: list of {batch_id, qty (base units)}
 */
class CreateSupplierReturnAction
{
    public function __construct(
        private readonly StockService $stock,
        private readonly SupplierLedger $ledger,
        private readonly DocumentNumber $numbers,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor): SupplierReturn
    {
        return DB::transaction(function () use ($data, $actor): SupplierReturn {
            $return = SupplierReturn::create([
                'number' => $this->numbers->next('SRN'),
                'supplier_id' => $data['supplier_id'],
                'goods_receipt_id' => $data['goods_receipt_id'] ?? null,
                'return_date' => $data['return_date'],
                'reason' => $data['reason'] ?? null,
                'total' => '0',
                'created_by' => $actor->id,
            ]);

            $batches = Batch::query()->with('product')->whereIn('id', array_column($data['lines'], 'batch_id'))->get()->keyBy('id');
            $total = BigDecimal::of('0.00');

            foreach ($data['lines'] as $line) {
                $batch = $batches->get((int) $line['batch_id']);
                $qty = Qty::of($line['qty']);

                $this->stock->issue($batch->product, $batch->variant_id, (string) $qty, MovementType::SupplierReturn, $return, $actor->id, $batch, $data['reason'] ?? null);

                $lineTotal = $qty->multipliedBy($batch->unit_cost)->toScale(2, RoundingMode::HalfUp);
                $total = $total->plus($lineTotal);

                SupplierReturnLine::create([
                    'supplier_return_id' => $return->id,
                    'product_id' => $batch->product_id,
                    'variant_id' => $batch->variant_id,
                    'batch_id' => $batch->id,
                    'qty' => (string) $qty,
                    'unit_cost' => $batch->unit_cost,
                    'line_total' => (string) $lineTotal,
                ]);
            }

            $return->total = (string) $total;
            $return->save();

            if ($total->isPositive()) {
                $this->ledger->debit($return->supplier_id, SupplierLedgerType::Return, $return, $total, $return->return_date, $actor->id, $return->reason);
            }

            return $return;
        });
    }
}
