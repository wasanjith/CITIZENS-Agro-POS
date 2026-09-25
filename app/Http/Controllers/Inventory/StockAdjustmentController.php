<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Actions\CreateStockAdjustmentAction;
use App\Domain\Inventory\Actions\ReviewStockAdjustmentAction;
use App\Domain\Inventory\Enums\AdjustmentReason;
use App\Domain\Inventory\Enums\AdjustmentStatus;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\System\Services\Settings;
use App\Http\Controllers\Concerns\HasListQuery;
use App\Http\Controllers\Concerns\PresentsProductLines;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\CreateStockAdjustmentRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StockAdjustmentController extends Controller
{
    use HasListQuery, PresentsProductLines;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', StockAdjustment::class);

        $adjustments = $this->applyListQuery(
            StockAdjustment::query()->with(['creator', 'approver'])->withCount('lines'),
            $request,
            searchable: ['number', 'note'],
            sortable: ['number', 'created_at', 'total_value'],
            filters: [
                'status' => function (Builder $query, string $status): void {
                    $query->where('status', $status);
                },
                'reason' => function (Builder $query, string $reason): void {
                    $query->where('reason', $reason);
                },
            ],
            defaultSort: 'created_at',
        )->paginate(25)->withQueryString();

        return view('inventory.adjustments.index', [
            'adjustments' => $adjustments,
            'statuses' => AdjustmentStatus::options(),
            'reasons' => AdjustmentReason::options(),
        ]);
    }

    /**
     * ?batch= starts with one line taking stock out of that batch (write-off from the expiry list).
     */
    public function create(Request $request, Settings $settings): View
    {
        $this->authorize('create', StockAdjustment::class);

        $lines = old('lines') !== null ? array_values((array) old('lines')) : [];
        $reason = old('reason');

        if ($lines === [] && ($batch = Batch::find($request->integer('batch'))) !== null) {
            $lines = [['product_id' => $batch->product_id, 'variant_id' => $batch->variant_id, 'batch_id' => $batch->id, 'qty' => '-'.StockLevel::where('batch_id', $batch->id)->value('qty_on_hand')]];
            $reason ??= $batch->expiry_date?->isPast() ? AdjustmentReason::Expired->value : null;
        }

        $rows = array_map(function (array $row): array {
            $row['batches'] = StockLevel::query()
                ->with('batch')
                ->where('product_id', $row['product_id'])
                ->where('variant_id', $row['variant_id'])
                ->where('qty_on_hand', '!=', 0)
                ->get()
                ->map(fn (StockLevel $level) => ['id' => $level->batch_id, 'label' => $level->batch->label(), 'on_hand' => $level->qty_on_hand, 'is_default' => $level->batch->isDefault()])
                ->values()
                ->all();

            return $row;
        }, $this->presentLines($lines));

        return view('inventory.adjustments.create', [
            'lines' => $rows,
            'reason' => $reason,
            'reasons' => AdjustmentReason::options(),
            'approvalLimit' => $settings->get('inventory.adjustment_approval_limit'),
            'canApproveAny' => $request->user()->can('approveAny', StockAdjustment::class),
        ]);
    }

    public function store(CreateStockAdjustmentRequest $request, CreateStockAdjustmentAction $create): RedirectResponse
    {
        $adjustment = $create->handle($request->validated(), $request->user());

        return redirect()->route('inventory.adjustments.show', $adjustment)->with('success', $adjustment->status === AdjustmentStatus::PendingApproval
            ? "{$adjustment->number} is waiting for the Super Admin's approval. Stock changes when it is approved."
            : "{$adjustment->number} posted. Stock is updated.");
    }

    public function show(StockAdjustment $adjustment): View
    {
        $this->authorize('view', $adjustment);

        $adjustment->load(['creator', 'approver', 'lines.product.baseUnit', 'lines.variant', 'lines.batch']);

        return view('inventory.adjustments.show', ['adjustment' => $adjustment]);
    }

    public function approve(Request $request, StockAdjustment $adjustment, ReviewStockAdjustmentAction $review): RedirectResponse
    {
        $this->authorize('approve', $adjustment);

        $review->approve($adjustment, $request->user());

        return back()->with('success', "{$adjustment->number} approved. Stock is updated.");
    }

    public function reject(Request $request, StockAdjustment $adjustment, ReviewStockAdjustmentAction $review): RedirectResponse
    {
        $this->authorize('approve', $adjustment);

        $validated = $request->validate(['rejection_reason' => ['required', 'string', 'max:255']]);

        $review->reject($adjustment, $request->user(), $validated['rejection_reason']);

        return back()->with('success', "{$adjustment->number} rejected. Stock did not change.");
    }
}
