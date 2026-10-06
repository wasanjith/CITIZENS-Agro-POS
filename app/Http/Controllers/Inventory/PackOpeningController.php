<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Actions\OpenPacksAction;
use App\Domain\Inventory\Models\PackOpening;
use App\Domain\Inventory\Services\StockService;
use App\Http\Controllers\Concerns\HasListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\OpenPacksRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Inventory → Open packs: sealed bags opened into loose stock when the loose stock
 * runs out before the next delivery. Packs opened on arrival are done on the GRN.
 */
class PackOpeningController extends Controller
{
    use HasListQuery;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', PackOpening::class);

        $openings = $this->applyListQuery(
            PackOpening::query()->with(['sealedProduct.baseUnit', 'looseProduct.baseUnit', 'goodsReceipt', 'creator']),
            $request,
            searchable: ['number', 'note'],
            sortable: ['number', 'created_at', 'cost_total'],
            defaultSort: 'created_at',
        )->paginate(25)->withQueryString();

        return view('inventory.pack-openings.index', ['openings' => $openings]);
    }

    public function create(Request $request, StockService $stock): View
    {
        $this->authorize('create', PackOpening::class);

        $products = Product::query()
            ->with(['baseUnit', 'opensInto.baseUnit'])
            ->whereNotNull('opens_into_product_id')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
        $onHand = $stock->totals($products->pluck('id')->all());

        return view('inventory.pack-openings.create', [
            'packs' => $products->map(fn (Product $product) => [
                'id' => $product->id,
                'label' => "{$product->short_code} · {$product->name}",
                'unit' => $product->baseUnit?->symbol,
                'available' => $onHand[$product->id][0]['available'] ?? '0.000',
                'loose' => $product->opensInto?->name,
                'loose_unit' => $product->opensInto?->baseUnit?->symbol,
                'per_pack' => $product->opens_into_qty,
            ])->values()->all(),
            'selected' => (int) old('sealed_product_id', $request->query('product')) ?: null,
        ]);
    }

    public function store(OpenPacksRequest $request, OpenPacksAction $open): RedirectResponse
    {
        $data = $request->validated();
        $sealed = Product::query()->findOrFail($data['sealed_product_id']);

        $opening = $open->handle($sealed, (string) $data['packs'], $data['weighed_qty'] !== null ? (string) $data['weighed_qty'] : null, $request->user(), note: $data['note'] ?? null);

        return redirect()->route('inventory.pack-openings.show', $opening)
            ->with('success', "{$opening->number} saved. Stock of both products is updated.");
    }

    public function show(PackOpening $packOpening): View
    {
        $this->authorize('view', $packOpening);

        $packOpening->load(['sealedProduct.baseUnit', 'looseProduct.baseUnit', 'goodsReceipt', 'creator']);

        return view('inventory.pack-openings.show', ['opening' => $packOpening]);
    }
}
