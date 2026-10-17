<?php

namespace App\Http\Controllers\Purchasing;

use App\Domain\Catalog\Actions\SaveProductAction;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\PriceList;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductSearchService;
use App\Domain\Catalog\Services\ShortCodeGenerator;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\Supplier;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * POST purchasing/purchase-orders/quick-product: "New product" on the purchase order form,
 * for a new product (often of a new brand) that arrives with a supplier. Creates the product
 * with its next short code, the brand if it is new, and links it to the supplier. Returns
 * the product as a search item, so the form adds it as a line.
 */
class QuickProductController extends Controller
{
    public function __invoke(Request $request, SaveProductAction $save, ShortCodeGenerator $codes, ProductSearchService $search): JsonResponse
    {
        $this->authorize('create', PurchaseOrder::class);
        $this->authorize('create', Product::class);

        $validated = $request->validate([
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:150'],
            'name_si' => ['nullable', 'string', 'max:150'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->whereNull('deleted_at')],
            'new_brand' => ['nullable', 'string', 'max:100'],
            'base_unit_id' => ['required', 'integer', 'exists:units,id'],
            'reference_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999999', 'decimal:0,4'],
            'retail_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'reorder_level' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
        ]);

        $shortCode = $codes->next(Category::find($validated['category_id']));

        if ($shortCode === null) {
            throw ValidationException::withMessages(['category_id' => 'This category has no free short code left. Add the product on the Products page.']);
        }

        $product = DB::transaction(function () use ($validated, $request, $save, $shortCode): Product {
            $brandId = $validated['brand_id'] ?? null;

            if (filled($validated['new_brand'] ?? null)) {
                $brandId = Brand::firstOrCreate(['name' => trim($validated['new_brand'])], ['is_active' => true])->id;
            }

            $retail = PriceList::query()->where('name', 'Retail')->value('id') ?? PriceList::default()?->id;

            $product = $save->handle([
                'short_code' => $shortCode,
                'name' => $validated['name'],
                'name_si' => $validated['name_si'] ?? null,
                'category_id' => $validated['category_id'],
                'brand_id' => $brandId,
                'base_unit_id' => $validated['base_unit_id'],
                'sold_loose' => false,
                'is_active' => true,
                'reorder_level' => $validated['reorder_level'] ?? 0,
                'reorder_qty' => 0,
                'reference_cost' => $validated['reference_cost'] ?? null,
                'units' => [['unit_id' => $validated['base_unit_id'], 'factor' => 1, 'is_default_sale' => true, 'is_default_purchase' => true]],
                'prices' => filled($validated['retail_price'] ?? null) && $retail !== null
                    ? [['unit_id' => $validated['base_unit_id'], 'price_list_id' => $retail, 'price' => $validated['retail_price']]]
                    : [],
            ], $request->user());

            if (isset($validated['supplier_id'])) {
                Supplier::findOrFail($validated['supplier_id'])->products()->syncWithoutDetaching([
                    $product->id => ['last_cost' => $product->reference_cost],
                ]);
            }

            return $product;
        });

        return response()->json($search->items([$product->id], $request->user())[0], 201);
    }
}
