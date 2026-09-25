<?php

namespace App\Http\Controllers\Pos;

use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\Sales\Actions\CreateQuotationAction;
use App\Domain\Sales\Enums\PrintDocumentType;
use App\Domain\Sales\Models\PrintJob;
use App\Domain\Sales\Models\Quotation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\CartRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Quotations on the counter screen: print one from the cart (F7), find open ones and
 * load one back into the cart to bill it.
 */
class QuotationController extends Controller
{
    public function __construct(private readonly CurrentTerminal $currentTerminal) {}

    /**
     * POST /api/pos/quotations {cart…, customer_name?, note?, idempotency_key}
     */
    public function store(CartRequest $request, CreateQuotationAction $create): JsonResponse
    {
        $this->authorize('create', Quotation::class);

        $extra = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:16', 'max:64'],
            'customer_name' => ['nullable', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $create->handle($this->currentTerminal->get(), $request->user(), $request->cart(), $extra['idempotency_key'], $extra['customer_name'] ?? null, $extra['note'] ?? null);
        $quotation = $result['quotation'];

        return response()->json([
            'quotation' => $this->summary($quotation),
            'created' => $result['created'],
            'print_url' => $result['print_job'] !== null ? route('pos.quotations.print', ['quotation' => $quotation, 'job' => $result['print_job']->id]) : null,
        ], $result['created'] ? 201 : 200);
    }

    /**
     * GET /api/pos/quotations?q=: open quotations (number, customer or phone).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Quotation::class);

        $term = trim((string) $request->query('q', ''));
        $digits = preg_replace('/\D+/', '', $term) ?? '';

        $quotations = Quotation::query()
            ->with(['customer', 'creator'])
            ->withCount('lines')
            ->usable()
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('customer_name', 'like', "%{$term}%")
                ->orWhere('number', 'like', "%{$term}%")
                ->orWhereHas('customer', fn (Builder $customer) => $customer->search($term))
                // Last digits, as on the cashier screen: "12" finds QT-2026-00012.
                ->when($digits !== '' && strlen($digits) <= 5, fn (Builder $number) => $number->orWhere('number', 'like', '%-'.str_pad(ltrim($digits, '0'), 5, '0', STR_PAD_LEFT)))))
            ->latest('id')
            ->limit(30)
            ->get();

        return response()->json(['quotations' => $quotations->map(fn (Quotation $quotation) => $this->summary($quotation))->all()]);
    }

    /**
     * GET /api/pos/quotations/{quotation}/cart: the lines to load into the counter cart.
     */
    public function cart(Quotation $quotation): JsonResponse
    {
        $this->authorize('view', $quotation);

        if (! $quotation->isUsable()) {
            throw ValidationException::withMessages(['quotation' => "{$quotation->number} is {$quotation->effectiveStatus()->label()}; make a new bill instead."]);
        }

        return response()->json(['quotation' => $this->summary($quotation), 'cart' => $quotation->toCart()]);
    }

    /**
     * POST /api/pos/quotations/{quotation}/reprint: a COPY on this counter's printer.
     */
    public function reprint(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorize('view', $quotation);

        $job = PrintJob::record(PrintDocumentType::Quotation, $quotation->id, $this->currentTerminal->get()->loadMissing('printer'), $request->user()->id, isCopy: true);

        return response()->json(['print_url' => route('pos.quotations.print', ['quotation' => $quotation, 'job' => $job->id])]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Quotation $quotation): array
    {
        $quotation->loadMissing(['customer', 'creator']);

        return [
            'id' => $quotation->id,
            'number' => $quotation->number,
            'status' => $quotation->effectiveStatus()->value,
            'customer' => $quotation->customerLabel(),
            'total' => $quotation->total,
            'valid_until' => $quotation->valid_until->toDateString(),
            'created_at' => $quotation->created_at->toIso8601String(),
            'staff' => $quotation->creator->name,
            'lines' => $quotation->lines_count ?? $quotation->lines()->count(),
        ];
    }
}
