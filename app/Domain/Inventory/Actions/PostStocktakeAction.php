<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Enums\StocktakeStatus;
use App\Domain\Inventory\Models\Stocktake;
use App\Domain\Inventory\Models\StocktakeLine;
use App\Domain\Inventory\Services\StockService;
use App\Domain\System\Services\Settings;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts a reviewed stocktake: every counted line with a difference becomes a STOCKTAKE
 * movement of (counted − system). Lines that were not counted are left as they are.
 * Differences worth more than the adjustment approval limit need inventory.adjust.approve.
 */
class PostStocktakeAction
{
    public function __construct(
        private readonly StockService $stock,
        private readonly Settings $settings,
    ) {}

    public function handle(Stocktake $stocktake, User $actor): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $actor): Stocktake {
            $stocktake = Stocktake::query()->lockForUpdate()->findOrFail($stocktake->id);

            if ($stocktake->status !== StocktakeStatus::Review) {
                throw ValidationException::withMessages(['status' => "{$stocktake->number} must be in review before posting."]);
            }

            $lines = $stocktake->lines()->with(['product', 'batch'])->whereNotNull('counted_qty')->orderBy('id')->get()
                ->filter(fn (StocktakeLine $line) => ! $line->variance()->isZero());

            $limit = BigDecimal::of((string) $this->settings->get('inventory.adjustment_approval_limit', 0));

            if (self::varianceValue($lines)->isGreaterThan($limit) && ! $actor->can('inventory.adjust.approve')) {
                throw ValidationException::withMessages(['status' => 'The differences are worth more than Rs. '.number_format((float) (string) $limit, 2).'. The Super Admin has to post this stocktake.']);
            }

            foreach ($lines as $line) {
                $this->stock->adjust(
                    $line->product,
                    $line->variant_id,
                    $line->batch,
                    (string) $line->variance(),
                    MovementType::Stocktake,
                    $stocktake,
                    $actor->id,
                    null,
                    $line->unit_cost,
                );
            }

            $stocktake->status = StocktakeStatus::Posted;
            $stocktake->posted_by = $actor->id;
            $stocktake->posted_at = now();
            $stocktake->save();

            return $stocktake;
        });
    }

    /**
     * Σ |counted − system| × unit cost.
     *
     * @param  Collection<int, StocktakeLine>  $lines
     */
    public static function varianceValue(Collection $lines): BigDecimal
    {
        return $lines->reduce(
            fn (BigDecimal $sum, StocktakeLine $line) => $sum->plus(($line->variance() ?? BigDecimal::zero())->abs()->multipliedBy($line->unit_cost)),
            BigDecimal::zero(),
        )->toScale(2, RoundingMode::HalfUp);
    }
}
