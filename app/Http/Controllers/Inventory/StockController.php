<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\OpeningStockEntry;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Actions\PostOpeningStockAction;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Services\StockAlerts;
use App\Domain\System\Services\Settings;
use App\Http\Controllers\Catalog\CategoryController;
use App\Http\Controllers\Concerns\HasListQuery;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StockController extends Controller
{
    use HasListQuery;

    /**
     * Stock on hand per product (default) or per batch (?view=batches).
     */
    public function index(Request $request, StockAlerts $alerts): View
    {
        abort_unless($request->user()->can('inventory.view'), 403);

        $showCost = $request->user()->can('viewCost', Product::class);
        $byBatch = $request->query('view') === 'batches';

        return view('inventory.stock.index', [
            'byBatch' => $byBatch,
            'showCost' => $showCost,
            'rows' => $byBatch ? $this->batchRows($request) : $this->productRows($request, $alerts, $showCost),
            'categories' => CategoryController::options(),
            'pendingOpening' => $request->user()->can('inventory.adjust') ? OpeningStockEntry::query()->whereNull('posted_at')->count() : 0,
            'totalValue' => $showCost ? (string) DB::table('stock_levels')->join('batches', 'batches.id', '=', 'stock_levels.batch_id')->selectRaw('COALESCE(SUM(stock_levels.qty_on_hand * batches.unit_cost), 0) AS value')->value('value') : null,
        ]);
    }

    /**
     * Batches with stock that expire within ?days= (30/60/90), expired ones first.
     */
    public function expiry(Request $request, StockAlerts $alerts, Settings $settings): View
    {
        abort_unless($request->user()->can('inventory.view'), 403);

        $days = in_array($request->integer('days'), [30, 60, 90, 180], true) ? $request->integer('days') : (int) $settings->get('inventory.expiry_alert_days', 30);

        $levels = $alerts->expiringLevels($days)
            ->with(['batch', 'product.baseUnit', 'variant'])
            ->orderBy('batches.expiry_date')
            ->paginate(50)
            ->withQueryString();

        return view('inventory.stock.expiry', [
            'levels' => $levels,
            'days' => $days,
            'showCost' => $request->user()->can('viewCost', Product::class),
        ]);
    }

    /**
     * Post opening stock left from imports made before inventory existed.
     */
    public function postOpening(Request $request, PostOpeningStockAction $post): RedirectResponse
    {
        abort_unless($request->user()->can('inventory.adjust'), 403);

        $count = $post->handle($request->user());

        return back()->with('success', "{$count} opening stock ".str('entry')->plural($count).' added to stock.');
    }

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    private function productRows(Request $request, StockAlerts $alerts, bool $showCost): LengthAwarePaginator
    {
        $query = Product::query()
            ->with(['category', 'baseUnit'])
            ->addSelect(['on_hand' => StockAlerts::onHandSubquery()])
            ->addSelect(['reserved' => DB::table('stock_levels')->selectRaw('COALESCE(SUM(qty_reserved), 0)')->whereColumn('stock_levels.product_id', 'products.id')])
            ->when($showCost, fn (Builder $query) => $query->addSelect(['stock_value' => DB::table('stock_levels')
                ->join('batches', 'batches.id', '=', 'stock_levels.batch_id')
                ->selectRaw('COALESCE(SUM(stock_levels.qty_on_hand * batches.unit_cost), 0)')
                ->whereColumn('stock_levels.product_id', 'products.id')]));

        return $this->applyListQuery(
            $query,
            $request,
            searchable: ['short_code', 'name', 'name_si', 'aliases'],
            sortable: ['short_code', 'name', 'on_hand'],
            filters: [
                'category' => function (Builder $query, string $categoryId): void {
                    $query->whereIn('category_id', Category::find($categoryId)?->descendantIdsAndSelf() ?? [0]);
                },
                'low' => function (Builder $query, string $value) use ($alerts): void {
                    if ($value === '1') {
                        $alerts->whereLowStock($query);
                    }
                },
                'stock' => function (Builder $query, string $value): void {
                    $sum = '(SELECT COALESCE(SUM(sl.qty_on_hand), 0) FROM stock_levels sl WHERE sl.product_id = products.id)';
                    match ($value) {
                        'in' => $query->whereRaw("{$sum} > 0"),
                        'out' => $query->whereRaw("{$sum} <= 0"),
                        default => null,
                    };
                },
                'status' => function (Builder $query, string $status): void {
                    $query->where('is_active', $status === 'active');
                },
            ],
            defaultSort: 'short_code',
            defaultDirection: 'asc',
        )->paginate(50)->withQueryString();
    }

    /**
     * @return LengthAwarePaginator<int, StockLevel>
     */
    private function batchRows(Request $request): LengthAwarePaginator
    {
        $search = trim((string) $request->query('search', ''));
        $categoryId = $request->input('filter.category');

        return StockLevel::query()
            ->join('batches', 'batches.id', '=', 'stock_levels.batch_id')
            ->join('products', 'products.id', '=', 'stock_levels.product_id')
            ->where('stock_levels.qty_on_hand', '!=', 0)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('products.short_code', 'like', "%{$search}%")
                ->orWhere('products.name', 'like', "%{$search}%")
                ->orWhere('batches.lot_no', 'like', "%{$search}%")))
            ->when($categoryId, fn (Builder $query) => $query->whereIn('products.category_id', Category::find($categoryId)?->descendantIdsAndSelf() ?? [0]))
            ->with(['batch', 'product.baseUnit', 'variant'])
            ->orderBy('products.short_code')
            ->orderByRaw('batches.expiry_date IS NULL, batches.expiry_date')
            ->select('stock_levels.*')
            ->paginate(50)
            ->withQueryString();
    }
}
