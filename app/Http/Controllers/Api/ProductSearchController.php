<?php

namespace App\Http\Controllers\Api;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductSearchService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/pos/search?q=5*urea&category_id=&brand_id=&price_list_id=&limit=
 * Used by the POS search box and the back-office top bar.
 */
class ProductSearchController extends Controller
{
    public function __invoke(Request $request, ProductSearchService $search): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'price_list_id' => ['nullable', 'integer', 'exists:price_lists,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $result = $search->search(
            (string) ($validated['q'] ?? ''),
            ['category_id' => $validated['category_id'] ?? null, 'brand_id' => $validated['brand_id'] ?? null],
            (int) ($validated['limit'] ?? 20),
            $request->user(),
            isset($validated['price_list_id']) ? (int) $validated['price_list_id'] : null,
        );

        return response()->json($result);
    }
}
