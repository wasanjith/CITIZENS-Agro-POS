<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Category;
use Illuminate\Support\Facades\DB;

/**
 * Suggests the next free numeric short code, inside the category's range when it has one
 * (Fertilizers 1000-1999, Bicycle Parts 5000-6999 …). Codes stay editable on the form.
 */
class ShortCodeGenerator
{
    /**
     * Codes above this are used when a category has no range of its own.
     */
    private const DEFAULT_FROM = 10000;

    /**
     * @param  list<string>  $reserved  codes already taken in the current batch (e.g. an import being built)
     */
    public function next(?Category $category = null, array $reserved = []): ?string
    {
        [$from, $to] = $category?->codeRange() ?? [self::DEFAULT_FROM, null];

        $taken = array_flip(array_merge($this->usedCodes($from, $to), array_map('intval', $reserved)));

        for ($code = $from; $to === null || $code <= $to; $code++) {
            if (! isset($taken[$code])) {
                return (string) $code;
            }
        }

        return null;
    }

    public function isTaken(string $code, ?int $ignoreProductId = null, ?int $ignoreVariantId = null): bool
    {
        return DB::table('products')->where('short_code', $code)->when($ignoreProductId, fn ($q) => $q->where('id', '!=', $ignoreProductId))->exists()
            || DB::table('product_variants')->where('short_code', $code)->when($ignoreVariantId, fn ($q) => $q->where('id', '!=', $ignoreVariantId))->exists();
    }

    /**
     * Numeric codes in the range used by products or variants, including deleted ones
     * (a deleted product's code is never handed out again, so old invoices stay unambiguous).
     *
     * @return list<int>
     */
    private function usedCodes(int $from, ?int $to): array
    {
        $codes = [];

        foreach (['products', 'product_variants'] as $table) {
            $query = DB::table($table)
                ->whereRaw("short_code REGEXP '^[0-9]+$'")
                ->whereRaw('CAST(short_code AS UNSIGNED) >= ?', [$from]);

            if ($to !== null) {
                $query->whereRaw('CAST(short_code AS UNSIGNED) <= ?', [$to]);
            }

            foreach ($query->pluck('short_code') as $code) {
                $codes[] = (int) $code;
            }
        }

        return $codes;
    }
}
