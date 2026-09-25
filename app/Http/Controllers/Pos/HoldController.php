<?php

namespace App\Http\Controllers\Pos;

use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\Sales\Actions\HoldCartAction;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\CartRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hold (F8) and recall bills on one counter.
 */
class HoldController extends Controller
{
    public function __construct(private readonly CurrentTerminal $currentTerminal) {}

    public function index(): JsonResponse
    {
        $holds = Sale::query()
            ->with('invoicedBy')
            ->withCount('items')
            ->where('status', SaleStatus::OnHold)
            ->where('invoiced_terminal_id', $this->currentTerminal->get()->id)
            ->latest()
            ->get();

        return response()->json([
            'holds' => $holds->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'total' => $sale->total,
                'lines' => $sale->items_count,
                'note' => $sale->note,
                'staff' => $sale->invoicedBy->name,
                'held_at' => $sale->created_at->toIso8601String(),
            ])->all(),
        ]);
    }

    public function store(CartRequest $request, HoldCartAction $hold): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null;

        $sale = $hold->hold($this->currentTerminal->get(), $request->user(), $request->cart(), $note);

        return response()->json(['hold_id' => $sale->id], 201);
    }

    public function recall(Request $request, Sale $sale, HoldCartAction $hold): JsonResponse
    {
        return response()->json(['cart' => $hold->recall($this->currentTerminal->get(), $request->user(), $sale)]);
    }
}
