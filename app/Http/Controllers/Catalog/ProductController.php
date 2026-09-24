<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\Actions\SaveProductAction;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\PriceList;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Services\PriceBook;
use App\Domain\Catalog\Services\ShortCodeGenerator;
use App\Http\Controllers\Concerns\HasListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SaveProductRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;

class ProductController extends Controller
{
    use HasListQuery;

    public function index(Request $request, PriceBook $priceBook): View
    {
        $this->authorize('viewAny', Product::class);

        $products = $this->applyListQuery(
            Product::query()->with(['category', 'brand', 'units.unit']),
            $request,
            searchable: ['short_code', 'name', 'name_si', 'aliases', 'sku'],
            sortable: ['short_code', 'name', 'created_at', 'updated_at'],
            filters: self::listFilters(),
            defaultSort: 'short_code',
            defaultDirection: 'asc',
        )->paginate(25)->withQueryString();

        $retail = PriceList::default();

        return view('catalog.products.index', [
            'products' => $products,
            'prices' => $retail ? $priceBook->forProducts($products->pluck('id')->all(), $retail->id) : [],
            'priceList' => $retail,
            'categories' => CategoryController::options(),
            'brands' => Brand::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    /**
     * Filters shared by the list page and the Excel export.
     *
     * @return array<string, callable(Builder<Product>, string): void>
     */
    public static function listFilters(): array
    {
        return [
            'category' => function (Builder $query, string $categoryId): void {
                $category = Category::find($categoryId);
                $query->whereIn('category_id', $category?->descendantIdsAndSelf() ?? [0]);
            },
            'brand' => function (Builder $query, string $brandId): void {
                $query->where('brand_id', $brandId);
            },
            'status' => function (Builder $query, string $status): void {
                $query->where('is_active', $status === 'active');
            },
        ];
    }

    public function show(Product $product, PriceBook $priceBook): View
    {
        $this->authorize('view', $product);

        $product->load(['category.parent.parent', 'brand', 'baseUnit', 'tax', 'units.unit', 'creator', 'variants' => fn ($query) => $query->withTrashed()]);

        return view('catalog.products.show', [
            'product' => $product,
            'priceLists' => PriceList::query()->orderBy('id')->get(),
            'prices' => $priceBook->forProduct($product->id),
            'priceHistory' => $product->prices()->with(['unit', 'priceList', 'creator'])->latest('effective_from')->latest('id')->limit(50)->get(),
            'openingStock' => $product->openingStock()->whereNull('posted_at')->get(),
            'activity' => Activity::query()
                ->where('subject_type', $product->getMorphClass())
                ->where('subject_id', $product->id)
                ->with('causer')
                ->latest('id')
                ->limit(20)
                ->get(),
        ]);
    }

    public function create(Request $request, ShortCodeGenerator $codes): View
    {
        $this->authorize('create', Product::class);

        $category = $request->integer('category_id') ? Category::find($request->integer('category_id')) : null;

        $product = new Product([
            'is_active' => true,
            'category_id' => $category?->id,
            'short_code' => $category ? $codes->next($category) : null,
            'reorder_level' => 0,
            'reorder_qty' => 0,
        ]);

        return view('catalog.products.form', $this->formData($product, []));
    }

    public function store(SaveProductRequest $request, SaveProductAction $saveProduct): RedirectResponse
    {
        $product = $saveProduct->handle($request->productData(), $request->user());

        return redirect()->route('catalog.products.show', $product)->with('success', "Product {$product->short_code} · {$product->name} created.");
    }

    public function edit(Product $product, PriceBook $priceBook): View
    {
        $this->authorize('update', $product);

        $product->load(['units', 'variants']);

        return view('catalog.products.form', $this->formData($product, $priceBook->forProduct($product->id)));
    }

    public function update(SaveProductRequest $request, Product $product, SaveProductAction $saveProduct): RedirectResponse
    {
        $saveProduct->handle($request->productData(), $request->user(), $product);

        return redirect()->route('catalog.products.show', $product)->with('success', "Product {$product->short_code} · {$product->name} updated.");
    }

    /**
     * Soft delete. Old invoices keep pointing at the product and its short code is never reused.
     */
    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);

        $product->delete();

        return redirect()->route('catalog.products.index')->with('success', "Product {$product->short_code} · {$product->name} deleted.");
    }

    /**
     * Suggest the next free short code for a category (used by the product form).
     */
    public function nextCode(Request $request, ShortCodeGenerator $codes): JsonResponse
    {
        $this->authorize('create', Product::class);

        $category = Category::find($request->integer('category_id'));

        return response()->json([
            'code' => $codes->next($category),
            'range' => $category?->codeRange(),
        ]);
    }

    /**
     * @param  array<int, array<int, string>>  $prices  price_list_id => [unit_id => price]
     * @return array<string, mixed>
     */
    private function formData(Product $product, array $prices): array
    {
        $units = Unit::query()->orderBy('name')->get();

        $unitRows = $product->exists
            ? $product->units->map(fn (ProductUnit $unit) => [
                'unit_id' => $unit->unit_id,
                'factor' => $unit->factor,
                'is_default_sale' => $unit->is_default_sale,
                'is_default_purchase' => $unit->is_default_purchase,
            ])->values()->all()
            : [];

        $variantRows = $product->exists
            ? $product->variants->map(fn (ProductVariant $variant) => [
                'id' => $variant->id,
                'short_code' => $variant->short_code,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'size' => $variant->attributes['size'] ?? '',
                'colour' => $variant->attributes['colour'] ?? '',
                'is_active' => $variant->is_active,
            ])->values()->all()
            : [];

        $attributeRows = collect($product->attributes ?? [])
            ->map(fn ($value, $key) => ['key' => $key, 'value' => $value])
            ->values()
            ->all();

        return [
            'product' => $product,
            'categories' => CategoryController::options(activeOnly: true),
            'brands' => Brand::query()->active()->orderBy('name')->pluck('name', 'id'),
            'units' => $units,
            'taxes' => Tax::query()->orderBy('name')->get()->mapWithKeys(fn (Tax $tax) => [$tax->id => $tax->label()]),
            'priceLists' => PriceList::query()->orderBy('id')->get(),
            'unitRows' => old('units', $unitRows),
            'variantRows' => old('variants', $variantRows),
            'attributeRows' => old('attribute_rows', $attributeRows),
            'priceMatrix' => old('prices') ? collect(old('prices'))->mapWithKeys(fn ($row) => ["{$row['price_list_id']}:{$row['unit_id']}" => $row['price']])->all()
                : collect($prices)->flatMap(fn ($byUnit, $listId) => collect($byUnit)->mapWithKeys(fn ($price, $unitId) => ["{$listId}:{$unitId}" => $price]))->all(),
        ];
    }
}
