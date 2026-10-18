<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Suggests the next free SKU from the category and brand: FER-BAT-001 (Fertilizers, Bathalagoda Agro),
 * or FER-001 without a brand. SKUs stay editable on the form.
 */
class SkuGenerator
{
    public function next(Category $category, ?Brand $brand = null): string
    {
        $prefix = self::abbreviate($category->name, 'C'.$category->id).'-'
            .($brand ? self::abbreviate($brand->name, 'B'.$brand->id).'-' : '');

        return $prefix.str_pad((string) ($this->highestNumber($prefix) + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * First three letters or digits of the name, upper case ("Agro-chemicals" → AGR).
     * Names with no Latin letters (Sinhala) fall back to the record id.
     */
    public static function abbreviate(string $name, string $fallback): string
    {
        $letters = preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($name));

        return $letters === '' ? $fallback : strtoupper(substr($letters, 0, 3));
    }

    /**
     * Highest number already used after the prefix by products or variants, including deleted ones.
     */
    private function highestNumber(string $prefix): int
    {
        $highest = 0;

        foreach (['products', 'product_variants'] as $table) {
            foreach (DB::table($table)->where('sku', 'like', $prefix.'%')->pluck('sku') as $sku) {
                $number = substr((string) $sku, strlen($prefix));

                if (ctype_digit($number)) {
                    $highest = max($highest, (int) $number);
                }
            }
        }

        return $highest;
    }
}
