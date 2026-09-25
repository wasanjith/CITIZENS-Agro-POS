<?php

namespace App\Http\Controllers\Pos;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\FavoriteProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductSearchService;
use App\Domain\Identity\Services\CashierAuthority;
use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\Sales\Actions\SyncCounterCartAction;
use App\Domain\Sales\Models\ApprovalRequest;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Services\LiveCartStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\CartRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Live cart of a counter: restore on load, sync on every change (Live Billing), and the
 * small "inbox" the counter polls when WebSockets are down.
 */
class CartController extends Controller
{
    public function __construct(
        private readonly CurrentTerminal $currentTerminal,
        private readonly LiveCartStore $store,
    ) {}

    /**
     * GET /api/pos/cart: the cart stored for this terminal (after a refresh or power cut).
     */
    public function show(Request $request): JsonResponse
    {
        $terminal = $this->currentTerminal->get();
        $cart = $this->store->get($terminal->id);

        return response()->json([
            'cart' => $cart,
            'void_restores' => $this->store->voidRestores($terminal->id),
            'approvals' => $cart !== null ? $this->approvals($cart['cart_uuid']) : [],
        ]);
    }

    /**
     * POST /api/pos/cart-sync
     */
    public function sync(CartRequest $request, SyncCounterCartAction $sync): JsonResponse
    {
        $snapshot = $sync->handle($this->currentTerminal->get(), $request->user(), $request->cart());

        return response()->json(['cart' => $snapshot]);
    }

    /**
     * GET /api/pos/inbox?cart_uuid=: what a counter would otherwise hear over WebSockets.
     */
    public function inbox(Request $request, CashierAuthority $authority): JsonResponse
    {
        $terminal = $this->currentTerminal->get();
        $uuid = (string) $request->query('cart_uuid', '');

        return response()->json([
            'void_restores' => $this->store->voidRestores($terminal->id),
            'approvals' => $uuid !== '' ? $this->approvals($uuid) : [],
            'cashier' => $authority->holderSummary(),
        ]);
    }

    public function dismissVoidRestore(Sale $sale): JsonResponse
    {
        $this->store->dismissVoidRestore($this->currentTerminal->get()->id, $sale->id);

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/pos/quick-items?tab=favorites|recent|category&category_id=
     */
    public function quickItems(Request $request, ProductSearchService $search): JsonResponse
    {
        $validated = $request->validate([
            'tab' => ['required', 'in:favorites,recent,category'],
            'category_id' => ['nullable', 'integer'],
            'price_list_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $terminal = $this->currentTerminal->get();

        $ids = match ($validated['tab']) {
            'favorites' => FavoriteProduct::query()
                ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', $user->id))
                ->orderByRaw('user_id IS NULL')
                ->orderBy('sort_order')
                ->pluck('product_id'),
            'recent' => DB::table('sale_items')
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->where('sales.invoiced_terminal_id', $terminal->id)
                ->whereNotNull('sales.invoice_no')
                ->groupBy('sale_items.product_id')
                ->orderByRaw('MAX(sale_items.id) DESC')
                ->limit(30)
                ->pluck('sale_items.product_id'),
            default => Product::query()
                ->active()
                ->whereIn('category_id', Category::find($validated['category_id'] ?? 0)?->descendantIdsAndSelf() ?? [0])
                ->orderByDesc('sales_velocity_30d')
                ->orderBy('name')
                ->limit(48)
                ->pluck('id'),
        };

        $ids = $ids->map(fn ($id) => (int) $id)->unique()->values()->all();

        return response()->json([
            'items' => $search->items($ids, $user, isset($validated['price_list_id']) ? (int) $validated['price_list_id'] : null),
        ]);
    }

    /**
     * Discount requests of one cart and their current state.
     *
     * @return list<array<string, mixed>>
     */
    private function approvals(string $cartUuid): array
    {
        return ApprovalRequest::query()
            ->with(['terminal', 'requester'])
            ->where('cart_uuid', $cartUuid)
            ->whereNull('sale_id')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (ApprovalRequest $request) => $request->toBroadcast())
            ->all();
    }
}
