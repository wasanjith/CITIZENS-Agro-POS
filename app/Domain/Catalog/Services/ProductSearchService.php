<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\PriceList;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Services\StockService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Product search for the POS and the back office.
 *
 *   1. "5*urea" → qty 5, term "urea".
 *   2. An exact short code (product or variant) returns just that item.
 *   3. Otherwise Meilisearch; if it is down, MySQL FULLTEXT (ngram) + LIKE on short codes.
 *   4. Hits are hydrated from MySQL with live prices and live stock (on hand − reserved).
 *   5. Cost fields are only included for users with catalog.cost.view.
 */
class ProductSearchService
{
    public const ENGINE_CODE = 'code';

    public const ENGINE_MEILISEARCH = 'meilisearch';

    public const ENGINE_MYSQL = 'mysql';

    public function __construct(
        private readonly PriceBook $priceBook,
        private readonly StockService $stock,
    ) {}

    /**
     * @param  array{category_id?: int|null, brand_id?: int|null, include_inactive?: bool}  $filters
     * @return array{term: string, qty: string|null, engine: string, items: list<array<string, mixed>>}
     */
    public function search(string $query, array $filters = [], int $limit = 20, ?User $user = null, ?int $priceListId = null): array
    {
        [$qty, $term] = self::parseQuantity($query);

        if ($term === '') {
            return ['term' => '', 'qty' => $qty, 'engine' => self::ENGINE_CODE, 'items' => []];
        }

        $priceListId ??= PriceList::default()?->id;
        $includeInactive = (bool) ($filters['include_inactive'] ?? false);

        $exact = $this->exactCode($term, $includeInactive);

        if ($exact !== null) {
            return [
                'term' => $term,
                'qty' => $qty,
                'engine' => self::ENGINE_CODE,
                'items' => $this->hydrate($exact[0], $priceListId, $user, onlyVariantId: $exact[1]),
            ];
        }

        [$ids, $engine] = $this->findIds($term, $filters, $limit);

        return [
            'term' => $term,
            'qty' => $qty,
            'engine' => $engine,
            'items' => array_slice($this->hydrate($ids, $priceListId, $user, $term), 0, $limit),
        ];
    }

    /**
     * Result items for known products, in the given order (POS favourites, recent, categories).
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    public function items(array $ids, ?User $user = null, ?int $priceListId = null): array
    {
        return $this->hydrate($ids, $priceListId ?? PriceList::default()?->id, $user);
    }

    /**
     * "5*urea" → ['5', 'urea'], "2.5 * tsp" → ['2.5', 'tsp'], "urea" → [null, 'urea'].
     *
     * @return array{0: string|null, 1: string}
     */
    public static function parseQuantity(string $query): array
    {
        $query = trim($query);

        if (preg_match('/^(\d+(?:\.\d{1,3})?)\s*\*\s*(.*)$/u', $query, $matches) === 1 && (float) $matches[1] > 0) {
            return [$matches[1], trim($matches[2])];
        }

        return [null, $query];
    }

    /**
     * @return array{0: list<int>, 1: int|null}|null product ids and the matched variant id
     */
    private function exactCode(string $term, bool $includeInactive): ?array
    {
        $code = mb_strtoupper($term);

        $product = Product::query()
            ->when(! $includeInactive, fn (Builder $query) => $query->active())
            ->where('short_code', $code)
            ->first();

        if ($product !== null) {
            return [[$product->id], null];
        }

        $variant = ProductVariant::query()
            ->where('short_code', $code)
            ->when(! $includeInactive, fn (Builder $query) => $query->where('is_active', true)
                ->whereHas('product', fn (Builder $product) => $product->active()))
            ->first();

        return $variant === null ? null : [[$variant->product_id], $variant->id];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: list<int>, 1: string}
     */
    private function findIds(string $term, array $filters, int $limit): array
    {
        if (config('scout.driver') === 'meilisearch') {
            try {
                return [$this->meilisearch($term, $filters, $limit), self::ENGINE_MEILISEARCH];
            } catch (Throwable $exception) {
                Log::warning('Meilisearch unavailable, using MySQL search.', ['error' => $exception->getMessage()]);
            }
        }

        return [$this->mysql($term, $filters, $limit), self::ENGINE_MYSQL];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<int>
     */
    private function meilisearch(string $term, array $filters, int $limit): array
    {
        $search = Product::search($term)->take($limit);

        if (! ($filters['include_inactive'] ?? false)) {
            $search->where('is_active', true);
        }
        if (! empty($filters['category_id'])) {
            $search->where('category_ids', (int) $filters['category_id']);
        }
        if (! empty($filters['brand_id'])) {
            $search->where('brand_id', (int) $filters['brand_id']);
        }

        return $search->keys()->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * Fallback: FULLTEXT with the ngram parser (handles Sinhala and partial words) plus
     * LIKE on product and variant codes and names.
     *
     * @param  array<string, mixed>  $filters
     * @return list<int>
     */
    private function mysql(string $term, array $filters, int $limit): array
    {
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        $columns = ['name', 'name_si', 'aliases', 'short_code'];
        $useFullText = mb_strlen($term) >= 2;

        $query = Product::query()
            ->select('products.id')
            ->when(! ($filters['include_inactive'] ?? false), fn (Builder $query) => $query->active())
            ->when(! empty($filters['category_id']), function (Builder $query) use ($filters): void {
                $category = Category::find($filters['category_id']);
                $query->whereIn('category_id', $category?->descendantIdsAndSelf() ?? [0]);
            })
            ->when(! empty($filters['brand_id']), fn (Builder $query) => $query->where('brand_id', $filters['brand_id']))
            ->where(function (Builder $query) use ($term, $like, $columns, $useFullText): void {
                if ($useFullText) {
                    $query->whereFullText($columns, $term);
                }
                $query->orWhere('short_code', 'like', "{$like}%")
                    ->orWhere('name', 'like', "%{$like}%")
                    ->orWhere('name_si', 'like', "%{$like}%")
                    ->orWhere('aliases', 'like', "%{$like}%")
                    ->orWhereHas('variants', fn (Builder $variants) => $variants
                        ->where('short_code', 'like', "{$like}%")
                        ->orWhere('name', 'like', "%{$like}%"));
            });

        if ($useFullText) {
            $query->orderByRaw('MATCH (name, name_si, aliases, short_code) AGAINST (? IN NATURAL LANGUAGE MODE) DESC', [$term]);
        }

        return $query
            ->orderByDesc('sales_velocity_30d')
            ->orderBy('name')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Load the products in the given order and turn them into result items. Products
     * with variants become one item per active variant (or just the matched variant).
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function hydrate(array $ids, ?int $priceListId, ?User $user, string $term = '', ?int $onlyVariantId = null): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var Collection<int, Product> $products */
        $products = Product::query()
            ->with(['brand', 'category', 'baseUnit', 'units.unit', 'variants' => fn ($query) => $query->where('is_active', true)])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $prices = $priceListId !== null ? $this->priceBook->forProducts($ids, $priceListId) : [];
        $showCost = $user?->can('viewCost', Product::class) ?? false;
        $stock = $this->stock->totals($ids);
        $expiry = $this->nearestExpiry($products->where('track_expiry', true)->keys()->all());
        $items = [];

        foreach ($ids as $id) {
            $product = $products->get($id);

            if ($product === null) {
                continue;
            }

            $base = $this->item($product, $prices[$id] ?? [], $showCost);
            $base['stock'] = $stock[$id][0]['available'] ?? '0.000';
            $base['expiry'] = $expiry[$id][0] ?? null;
            $variants = $onlyVariantId !== null
                ? $product->variants->where('id', $onlyVariantId)
                : $this->matchingVariantsFirst($product->variants, $term);

            if ($product->has_variants && $variants->isNotEmpty()) {
                foreach ($variants as $variant) {
                    $items[] = [
                        ...$base,
                        'key' => "{$product->id}-{$variant->id}",
                        'variant_id' => $variant->id,
                        'short_code' => $variant->short_code,
                        'name' => "{$product->name} {$variant->name}",
                        'variant_name' => $variant->name,
                        'stock' => $stock[$id][$variant->id]['available'] ?? '0.000',
                        'expiry' => $expiry[$id][$variant->id] ?? null,
                    ];
                }
            } else {
                $items[] = $base;
            }
        }

        return $items;
    }

    /**
     * Earliest expiry date of batches that still have stock to sell (the one FEFO sells next).
     *
     * @param  list<int>  $productIds
     * @return array<int, array<int, string>> product_id => [variant_id or 0 => Y-m-d]
     */
    private function nearestExpiry(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = DB::table('stock_levels')
            ->join('batches', 'batches.id', '=', 'stock_levels.batch_id')
            ->whereIn('stock_levels.product_id', $productIds)
            ->whereNotNull('batches.expiry_date')
            ->whereRaw('stock_levels.qty_on_hand - stock_levels.qty_reserved > 0')
            ->groupBy('stock_levels.product_id', 'stock_levels.variant_id')
            ->selectRaw('stock_levels.product_id, stock_levels.variant_id, MIN(batches.expiry_date) AS expiry')
            ->get();

        $expiry = [];

        foreach ($rows as $row) {
            $expiry[(int) $row->product_id][(int) ($row->variant_id ?? 0)] = substr((string) $row->expiry, 0, 10);
        }

        return $expiry;
    }

    /**
     * "tyre 26" lists the 26" variant before the 28" one.
     *
     * @param  Collection<int, ProductVariant>  $variants
     * @return Collection<int, ProductVariant>
     */
    private function matchingVariantsFirst(Collection $variants, string $term): Collection
    {
        $tokens = array_filter(preg_split('/[\s"\']+/u', mb_strtolower($term)) ?: []);

        return $variants->sortByDesc(function (ProductVariant $variant) use ($tokens): int {
            $name = mb_strtolower(str_replace(['"', "'"], '', $variant->name));

            return count(array_filter($tokens, fn (string $token) => str_contains($name, $token)));
        })->values();
    }

    /**
     * @param  array<int, string>  $prices  unit_id => price
     * @return array<string, mixed>
     */
    private function item(Product $product, array $prices, bool $showCost): array
    {
        $units = $product->units->map(fn (ProductUnit $unit) => [
            'id' => $unit->unit_id,
            'name' => $unit->unit->name,
            'symbol' => $unit->unit->symbol,
            'factor' => $unit->factor,
            'allows_decimal' => $unit->unit->allows_decimal,
            'price' => $prices[$unit->unit_id] ?? null,
            'is_default_sale' => $unit->is_default_sale,
            'is_default_purchase' => $unit->is_default_purchase,
        ])->values()->all();

        $default = collect($units)->firstWhere('is_default_sale', true) ?? ($units[0] ?? null);

        $item = [
            'key' => (string) $product->id,
            'id' => $product->id,
            'variant_id' => null,
            'short_code' => $product->short_code,
            'name' => $product->name,
            'variant_name' => null,
            'name_si' => $product->name_si,
            'brand' => $product->brand?->name,
            'category' => $product->category?->name,
            'is_active' => $product->is_active,
            'unit' => $default,
            'price' => $default['price'] ?? null,
            'units' => $units,
            // Available stock in base units (on hand − reserved), filled in by hydrate().
            'stock' => null,
            'base_unit' => $product->baseUnit?->symbol,
            'reorder_level' => $product->reorder_level,
            'track_batches' => $product->track_batches,
            'track_expiry' => $product->track_expiry,
            'url' => route('catalog.products.show', $product),
        ];

        if ($showCost) {
            $item['reference_cost'] = $product->reference_cost;
            $item['min_selling_margin_pct'] = $product->min_selling_margin_pct;
        }

        return $item;
    }
}
