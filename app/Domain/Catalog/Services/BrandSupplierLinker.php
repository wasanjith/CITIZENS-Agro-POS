<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Product;
use App\Domain\Purchasing\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * Keeps supplier_products in step with brands: a brand comes from one supplier, so its products
 * are linked to that supplier (and a new purchase order suggests them). When a brand changes
 * supplier, or a product changes brand, the link moves with its supplier code and last cost.
 */
class BrandSupplierLinker
{
    /**
     * Link all of the brand's products to its supplier, moving them away from the previous one.
     */
    public function linkBrand(Brand $brand, ?int $previousSupplierId = null): void
    {
        if ($brand->supplier_id === null) {
            return;
        }

        foreach ($brand->products()->get(['id', 'reference_cost']) as $product) {
            $this->move($product, $previousSupplierId, $brand->supplier_id);
        }
    }

    /**
     * Link a new product, or one that changed brand, to its brand's supplier.
     */
    public function linkProduct(Product $product, ?int $previousBrandId = null): void
    {
        $supplierId = $product->brand_id ? Brand::query()->whereKey($product->brand_id)->value('supplier_id') : null;

        if ($supplierId === null) {
            return;
        }

        $previousSupplierId = $previousBrandId ? Brand::query()->whereKey($previousBrandId)->value('supplier_id') : null;

        $this->move($product, $previousSupplierId, $supplierId);
    }

    private function move(Product $product, ?int $fromSupplierId, int $toSupplierId): void
    {
        $links = DB::table('supplier_products')->where('product_id', $product->id);
        $hasNew = (clone $links)->where('supplier_id', $toSupplierId)->exists();

        if ($fromSupplierId !== null && $fromSupplierId !== $toSupplierId) {
            if ($hasNew) {
                (clone $links)->where('supplier_id', $fromSupplierId)->delete();
            } elseif ((clone $links)->where('supplier_id', $fromSupplierId)->update(['supplier_id' => $toSupplierId, 'updated_at' => now()]) > 0) {
                return;
            }
        }

        if (! $hasNew) {
            Supplier::query()->findOrFail($toSupplierId)->products()->attach($product->id, ['last_cost' => $product->reference_cost]);
        }
    }
}
