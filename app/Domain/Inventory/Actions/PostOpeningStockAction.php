<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Catalog\Models\OpeningStockEntry;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\StockService;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Turns opening stock from the product import into OPENING stock movements.
 * Each entry is posted once (posted_at). Cost = the entry's cost, else the product's
 * reference cost. Entries with a lot number or expiry date get their own batch.
 */
class PostOpeningStockAction
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * @param  list<int>|null  $entryIds  null = every unposted entry
     * @return int number of entries posted
     */
    public function handle(?User $actor = null, ?array $entryIds = null): int
    {
        return DB::transaction(function () use ($actor, $entryIds): int {
            $entries = OpeningStockEntry::query()
                ->whereNull('posted_at')
                ->when($entryIds !== null, fn ($query) => $query->whereIn('id', $entryIds))
                ->with('product')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $posted = 0;

            foreach ($entries as $entry) {
                if ($entry->product !== null && BigDecimal::of($entry->qty)->isPositive()) {
                    $this->stock->receive(
                        $entry->product,
                        $entry->variant_id,
                        $entry->qty,
                        $entry->unit_cost ?? $entry->product->reference_cost ?? '0',
                        ['lot_no' => $entry->lot_no, 'expiry_date' => $entry->expiry_date?->toDateString()],
                        $entry,
                        MovementType::Opening,
                        $actor->id ?? $entry->created_by,
                    );
                }

                $entry->posted_at = now();
                $entry->save();
                $posted++;
            }

            return $posted;
        });
    }
}
