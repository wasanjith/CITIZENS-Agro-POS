<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\StocktakeStatus;
use App\Domain\Inventory\Models\Stocktake;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * COUNTING ⇄ REVIEW, and cancelling a stocktake that was not posted.
 */
class UpdateStocktakeStatusAction
{
    public function finishCounting(Stocktake $stocktake): Stocktake
    {
        return $this->move($stocktake, [StocktakeStatus::Counting], StocktakeStatus::Review);
    }

    public function reopenCounting(Stocktake $stocktake): Stocktake
    {
        return $this->move($stocktake, [StocktakeStatus::Review], StocktakeStatus::Counting);
    }

    public function cancel(Stocktake $stocktake): Stocktake
    {
        return $this->move($stocktake, [StocktakeStatus::Open, StocktakeStatus::Counting, StocktakeStatus::Review], StocktakeStatus::Cancelled);
    }

    /**
     * @param  list<StocktakeStatus>  $from
     */
    private function move(Stocktake $stocktake, array $from, StocktakeStatus $to): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $from, $to): Stocktake {
            $stocktake = Stocktake::query()->lockForUpdate()->findOrFail($stocktake->id);

            if (! in_array($stocktake->status, $from, true)) {
                throw ValidationException::withMessages(['status' => "{$stocktake->number} is {$stocktake->status->label()}."]);
            }

            $stocktake->status = $to;
            $stocktake->save();

            return $stocktake;
        });
    }
}
