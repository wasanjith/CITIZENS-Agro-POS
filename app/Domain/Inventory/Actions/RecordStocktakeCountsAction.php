<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\StocktakeStatus;
use App\Domain\Inventory\Models\Stocktake;
use App\Domain\Inventory\Support\Qty;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves counted quantities (base units) while the stocktake is in COUNTING.
 * An empty value clears the count (the line is then left unchanged when posting).
 */
class RecordStocktakeCountsAction
{
    /**
     * @param  array<int|string, string|null>  $counts  stocktake_line id => counted qty
     * @return int lines changed
     */
    public function handle(Stocktake $stocktake, array $counts, User $actor): int
    {
        return DB::transaction(function () use ($stocktake, $counts, $actor): int {
            $stocktake = Stocktake::query()->lockForUpdate()->findOrFail($stocktake->id);

            if ($stocktake->status !== StocktakeStatus::Counting) {
                throw ValidationException::withMessages(['status' => "{$stocktake->number} is not being counted any more."]);
            }

            $changed = 0;
            $lines = $stocktake->lines()->whereIn('id', array_keys($counts))->get();

            foreach ($lines as $line) {
                $value = $counts[$line->id];
                $counted = $value === null || $value === '' ? null : (string) Qty::of($value);

                if ($counted === $line->counted_qty) {
                    continue;
                }

                $line->counted_qty = $counted;
                $line->counted_by = $counted === null ? null : $actor->id;
                $line->counted_at = $counted === null ? null : now();
                $line->save();
                $changed++;
            }

            return $changed;
        });
    }
}
