<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Actions\PostStocktakeAction;
use App\Domain\Inventory\Actions\RecordStocktakeCountsAction;
use App\Domain\Inventory\Actions\StartStocktakeAction;
use App\Domain\Inventory\Actions\UpdateStocktakeStatusAction;
use App\Domain\Inventory\Enums\StocktakeStatus;
use App\Domain\Inventory\Models\Stocktake;
use App\Domain\Inventory\Models\StocktakeLine;
use App\Domain\System\Services\Settings;
use App\Http\Controllers\Catalog\CategoryController;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StocktakeController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Stocktake::class);

        return view('inventory.stocktakes.index', [
            'stocktakes' => Stocktake::query()
                ->with(['starter', 'poster'])
                ->withCount(['lines', 'lines as counted_count' => fn ($query) => $query->whereNotNull('counted_qty')])
                ->latest('id')
                ->paginate(25),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Stocktake::class);

        return view('inventory.stocktakes.create', [
            'categories' => CategoryController::options(activeOnly: true),
        ]);
    }

    public function store(Request $request, StartStocktakeAction $start): RedirectResponse
    {
        $this->authorize('create', Stocktake::class);

        $validated = $request->validate([
            'categories' => ['array'],
            'categories.*' => ['integer', 'exists:categories,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $stocktake = $start->handle(array_map('intval', $validated['categories'] ?? []), $validated['note'] ?? null, $request->user());

        return redirect()->route('inventory.stocktakes.count', $stocktake)->with('success', "{$stocktake->number} started. System quantities are frozen now; count the shelves and enter what you find.");
    }

    /**
     * Review page: system vs counted, differences and their value.
     */
    public function show(Request $request, Stocktake $stocktake): View
    {
        $this->authorize('view', $stocktake);

        $lines = $this->lines($stocktake);
        $differences = $lines->filter(fn (StocktakeLine $line) => $line->counted_qty !== null && ! $line->variance()->isZero());

        return view('inventory.stocktakes.show', [
            'stocktake' => $stocktake->load(['starter', 'poster']),
            'lines' => $request->query('show') === 'all' ? $lines : $differences,
            'showAll' => $request->query('show') === 'all',
            'totalLines' => $lines->count(),
            'countedLines' => $lines->whereNotNull('counted_qty')->count(),
            'differenceCount' => $differences->count(),
            'varianceValue' => PostStocktakeAction::varianceValue($differences),
            'approvalLimit' => app(Settings::class)->get('inventory.adjustment_approval_limit'),
            'showCost' => $request->user()->can('viewCost', Product::class),
        ]);
    }

    /**
     * Tablet-friendly counting page. Each count is saved as soon as it is entered.
     */
    public function count(Stocktake $stocktake): View|RedirectResponse
    {
        $this->authorize('view', $stocktake);

        if ($stocktake->status !== StocktakeStatus::Counting) {
            return redirect()->route('inventory.stocktakes.show', $stocktake);
        }

        return view('inventory.stocktakes.count', [
            'stocktake' => $stocktake,
            'lines' => $this->lines($stocktake),
        ]);
    }

    public function saveCounts(Request $request, Stocktake $stocktake, RecordStocktakeCountsAction $record): JsonResponse|RedirectResponse
    {
        $this->authorize('count', $stocktake);

        $validated = $request->validate([
            'counts' => ['required', 'array', 'max:500'],
            'counts.*' => ['nullable', 'numeric', 'min:0', 'max:99999999999', 'decimal:0,3'],
        ]);

        $changed = $record->handle($stocktake, $validated['counts'], $request->user());

        if ($request->expectsJson()) {
            return response()->json(['saved' => $changed]);
        }

        return back()->with('success', "{$changed} ".str('count')->plural($changed).' saved.');
    }

    /**
     * Printable count sheet (no system quantities, so the count is blind).
     */
    public function sheet(Stocktake $stocktake): View
    {
        $this->authorize('view', $stocktake);

        return view('inventory.stocktakes.sheet', [
            'stocktake' => $stocktake,
            'lines' => $this->lines($stocktake),
        ]);
    }

    public function finish(Stocktake $stocktake, UpdateStocktakeStatusAction $status): RedirectResponse
    {
        $this->authorize('review', $stocktake);

        $status->finishCounting($stocktake);

        return redirect()->route('inventory.stocktakes.show', $stocktake)->with('success', 'Counting finished. Check the differences, then post.');
    }

    public function reopen(Stocktake $stocktake, UpdateStocktakeStatusAction $status): RedirectResponse
    {
        $this->authorize('review', $stocktake);

        $status->reopenCounting($stocktake);

        return redirect()->route('inventory.stocktakes.count', $stocktake)->with('success', 'Counting reopened.');
    }

    public function post(Request $request, Stocktake $stocktake, PostStocktakeAction $post): RedirectResponse
    {
        $this->authorize('post', $stocktake);

        $post->handle($stocktake, $request->user());

        return redirect()->route('inventory.stocktakes.show', $stocktake)->with('success', "{$stocktake->number} posted. Stock now matches the count.");
    }

    public function cancel(Stocktake $stocktake, UpdateStocktakeStatusAction $status): RedirectResponse
    {
        $this->authorize('cancel', $stocktake);

        $status->cancel($stocktake);

        return redirect()->route('inventory.stocktakes.show', $stocktake)->with('success', "{$stocktake->number} cancelled. Stock did not change.");
    }

    /**
     * Lines in shelf order: category, then product code, then expiry.
     *
     * @return Collection<int, StocktakeLine>
     */
    private function lines(Stocktake $stocktake): Collection
    {
        return $stocktake->lines()
            ->join('products', 'products.id', '=', 'stocktake_lines.product_id')
            ->leftJoin('batches', 'batches.id', '=', 'stocktake_lines.batch_id')
            ->with(['product.baseUnit', 'product.category', 'variant', 'batch', 'counter'])
            ->orderBy('products.category_id')
            ->orderBy('products.short_code')
            ->orderBy('stocktake_lines.variant_id')
            ->orderByRaw('batches.expiry_date IS NULL, batches.expiry_date')
            ->select('stocktake_lines.*')
            ->get();
    }
}
