<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Data\StockAllocation;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Models\PackOpening;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Inventory\Support\Qty;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\System\Services\DocumentNumber;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens sealed packs into the loose product they are set up for (Product → "Can be
 * opened into"): Urea 50kg bag −3, Urea (loose) +150 kg.
 *
 *   - the packs leave stock first-expiry-first-out; their cost moves in full to the
 *     loose stock, so cost per kg = cost of the packs ÷ weight put into loose stock
 *   - the weight defaults to packs × the pack's loose quantity; a different weighed
 *     amount is kept, so short-weight packs show up on the opening
 *   - the loose product's reference cost becomes the new cost per base unit
 *   - lot and expiry carry over when the loose product tracks batches or expiry
 */
class OpenPacksAction
{
    public function __construct(
        private readonly StockService $stock,
        private readonly DocumentNumber $numbers,
    ) {}

    /**
     * @param  string  $packs  packs to open, in the sealed product's base unit
     * @param  string|null  $weighedQty  what went into loose stock, in the loose product's base unit (null = nominal)
     * @param  string  $field  form field that errors are reported on
     * @param  string  $weighedField  form field for weight errors
     */
    public function handle(
        Product $sealed,
        string $packs,
        ?string $weighedQty,
        User $actor,
        ?GoodsReceipt $receipt = null,
        ?string $note = null,
        string $field = 'packs',
        string $weighedField = 'weighed_qty',
    ): PackOpening {
        $sealed->loadMissing(['opensInto.baseUnit', 'baseUnit']);
        $loose = $sealed->opensInto;

        if ($loose === null || $sealed->opens_into_qty === null) {
            throw ValidationException::withMessages([$field => "{$sealed->name} cannot be opened: set \"Can be opened into\" on the product first."]);
        }

        if ($loose->trashed()) {
            throw ValidationException::withMessages([$field => "{$loose->name} is deleted, so {$sealed->name} cannot be opened into it."]);
        }

        if ($sealed->has_variants) {
            throw ValidationException::withMessages([$field => "{$sealed->name} has variants and cannot be opened."]);
        }

        $packQty = Qty::of($packs);

        if (! $packQty->isPositive()) {
            throw ValidationException::withMessages([$field => 'Enter how many packs to open.']);
        }

        $expected = $packQty->multipliedBy($sealed->opens_into_qty)->toScale(Qty::SCALE, RoundingMode::HalfUp);
        $weighed = $weighedQty !== null && trim($weighedQty) !== '' ? Qty::of($weighedQty) : $expected;

        if (! $weighed->isPositive()) {
            throw ValidationException::withMessages([$weighedField => 'The weighed quantity must be above 0.']);
        }

        return DB::transaction(function () use ($sealed, $loose, $packQty, $expected, $weighed, $actor, $receipt, $note): PackOpening {
            $this->numbers->ensure('OPN', 'OPN-{Y}-', 5);

            $opening = PackOpening::create([
                'number' => $this->numbers->next('OPN'),
                'sealed_product_id' => $sealed->id,
                'packs' => (string) $packQty,
                'loose_product_id' => $loose->id,
                'expected_qty' => (string) $expected,
                'weighed_qty' => (string) $weighed,
                'cost_total' => '0',
                'goods_receipt_id' => $receipt?->id,
                'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
                'created_by' => $actor->id,
            ]);

            $allocations = $this->stock->issue($sealed, null, (string) $packQty, MovementType::RepackOut, $opening, $actor->id, note: "Opened into {$loose->name}");

            $cost = array_reduce(
                $allocations,
                fn (BigDecimal $sum, StockAllocation $allocation) => $sum->plus(BigDecimal::of($allocation->qty)->multipliedBy($allocation->unitCost)),
                BigDecimal::zero(),
            );
            $costPerUnit = $cost->dividedBy($weighed, 4, RoundingMode::HalfUp);

            $this->stock->receive(
                $loose,
                null,
                (string) $weighed,
                (string) $costPerUnit,
                $this->batchData($loose, $allocations),
                $opening,
                MovementType::RepackIn,
                $actor->id,
                "From {$opening->number}: {$sealed->name}",
            );

            $opening->cost_total = (string) $cost->toScale(2, RoundingMode::HalfUp);
            $opening->save();

            if ($costPerUnit->isPositive()) {
                Product::withoutSyncingToSearch(fn () => $loose->update(['reference_cost' => (string) $costPerUnit]));
            }

            return $opening;
        });
    }

    /**
     * Lot and dates of the first opened batch, for a loose product that tracks them.
     *
     * @param  list<StockAllocation>  $allocations
     * @return array{lot_no?: string|null, mfg_date?: string|null, expiry_date?: string|null}
     */
    private function batchData(Product $loose, array $allocations): array
    {
        if ((! $loose->track_batches && ! $loose->track_expiry) || $allocations === []) {
            return [];
        }

        $batch = Batch::query()->find($allocations[0]->batchId);

        return [
            'lot_no' => $batch?->lot_no,
            'mfg_date' => $batch?->mfg_date?->toDateString(),
            'expiry_date' => $batch?->expiry_date?->toDateString(),
        ];
    }
}
