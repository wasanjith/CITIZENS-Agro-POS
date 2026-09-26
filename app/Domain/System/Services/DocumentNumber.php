<?php

namespace App\Domain\System\Services;

use App\Domain\System\Enums\SequenceResetPeriod;
use App\Domain\System\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Gapless document numbers (invoice, PO, GRN …).
 *
 * The sequence row is locked FOR UPDATE, so concurrent callers queue up. When called
 * inside a larger transaction (e.g. issuing an invoice) a rollback also rolls back the
 * number, so no gaps appear.
 */
class DocumentNumber
{
    public function next(string $type): string
    {
        return DB::transaction(function () use ($type): string {
            $sequence = DocumentSequence::query()
                ->where('type', $type)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                throw new RuntimeException("No document sequence is defined for [{$type}].");
            }

            $periodKey = $sequence->reset_period->periodKey(now());

            if ($periodKey !== $sequence->period_key) {
                $sequence->period_key = $periodKey;
                $sequence->next_number = 1;
            }

            $number = $sequence->next_number;
            $sequence->next_number = $number + 1;
            $sequence->save();

            return $this->format($sequence->prefix, $number, $sequence->padding);
        });
    }

    /**
     * Create the sequence if it does not exist yet (documents added after go-live).
     */
    public function ensure(string $type, string $prefix, int $padding, SequenceResetPeriod $reset = SequenceResetPeriod::Yearly): void
    {
        if (DocumentSequence::query()->where('type', $type)->exists()) {
            return;
        }

        DocumentSequence::query()->toBase()->upsert([[
            'type' => $type,
            'prefix' => $prefix,
            'padding' => $padding,
            'reset_period' => $reset->value,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['type'], ['type']);
    }

    public function format(string $prefix, int $number, int $padding): string
    {
        $prefix = strtr($prefix, [
            '{Y}' => now()->format('Y'),
            '{y}' => now()->format('y'),
            '{m}' => now()->format('m'),
            '{d}' => now()->format('d'),
        ]);

        return $prefix.str_pad((string) $number, $padding, '0', STR_PAD_LEFT);
    }
}
