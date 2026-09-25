<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\StockMovement;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The stock ledger with filters: product, type, user, date range.
 */
class StockMovementController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('inventory.view'), 403);

        $validated = $request->validate([
            'product' => ['nullable', 'integer'],
            'type' => ['nullable', 'string'],
            'user' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $product = isset($validated['product']) ? Product::withTrashed()->find($validated['product']) : null;
        $type = MovementType::tryFrom((string) ($validated['type'] ?? ''));

        $movements = StockMovement::query()
            ->with(['product.baseUnit', 'variant', 'batch', 'user', 'reference'])
            ->when($product, fn (Builder $query) => $query->where('product_id', $product->id))
            ->when($type, fn (Builder $query) => $query->where('type', $type))
            ->when($validated['user'] ?? null, fn (Builder $query, $userId) => $query->where('user_id', $userId))
            ->when($validated['from'] ?? null, fn (Builder $query, $from) => $query->where('created_at', '>=', $from))
            ->when($validated['to'] ?? null, fn (Builder $query, $to) => $query->where('created_at', '<', now()->parse($to)->addDay()))
            ->orderByDesc('id')
            ->simplePaginate(50)
            ->withQueryString();

        return view('inventory.movements.index', [
            'movements' => $movements,
            'product' => $product,
            'types' => MovementType::options(),
            'users' => User::query()->orderBy('name')->pluck('name', 'id'),
            'showCost' => $request->user()->can('viewCost', Product::class),
        ]);
    }
}
