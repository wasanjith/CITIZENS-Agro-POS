<?php

namespace App\Http\Controllers\Purchasing;

use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Purchasing\Actions\CreateSupplierReturnAction;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceiptLine;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Models\SupplierReturn;
use App\Http\Controllers\Concerns\HasListQuery;
use App\Http\Controllers\Concerns\PresentsProductLines;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

class SupplierReturnController extends Controller
{
    use HasListQuery, PresentsProductLines;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', SupplierReturn::class);

        $returns = $this->applyListQuery(
            SupplierReturn::query()->with(['supplier', 'goodsReceipt', 'creator'])->withCount('lines'),
            $request,
            searchable: ['number', 'reason'],
            sortable: ['number', 'return_date', 'total'],
            filters: [
                'supplier' => function (Builder $query, string $supplierId): void {
                    $query->where('supplier_id', $supplierId);
                },
            ],
            defaultSort: 'return_date',
        )->paginate(25)->withQueryString();

        return view('purchasing.supplier-returns.index', [
            'returns' => $returns,
            'suppliers' => Supplier::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    /**
     * ?goods_receipt= starts from the batches that GRN created.
     */
    public function create(Request $request): View
    {
        $this->authorize('create', SupplierReturn::class);

        $receipt = $request->integer('goods_receipt')
            ? GoodsReceipt::query()->where('status', GoodsReceiptStatus::Posted)->with(['supplier', 'lines'])->find($request->integer('goods_receipt'))
            : null;

        $lines = old('lines') !== null
            ? array_values((array) old('lines'))
            : ($receipt?->lines->whereNotNull('batch_id')->map(fn (GoodsReceiptLine $line) => [
                'product_id' => $line->product_id,
                'variant_id' => $line->variant_id,
                'batch_id' => $line->batch_id,
                'qty' => '',
            ])->values()->all() ?? []);

        $supplierId = old('supplier_id') ?? $receipt?->supplier_id;

        return view('purchasing.supplier-returns.create', [
            'receipt' => $receipt,
            'lines' => $this->withBatches($this->presentLines($lines)),
            'supplierId' => $supplierId,
            'supplierName' => $supplierId ? Supplier::withTrashed()->find($supplierId)?->name : '',
        ]);
    }

    public function store(Request $request, CreateSupplierReturnAction $create): RedirectResponse
    {
        $this->authorize('create', SupplierReturn::class);

        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'goods_receipt_id' => ['nullable', 'integer', 'exists:goods_receipts,id'],
            'return_date' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.variant_id' => ['nullable', 'integer'],
            'lines.*.batch_id' => ['required', 'integer', 'exists:batches,id', 'distinct'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0', 'max:99999999999', 'decimal:0,3'],
        ], [
            'lines.required' => 'Add at least one product to return.',
            'lines.*.batch_id.required' => 'Choose the batch the goods come from.',
            'lines.*.batch_id.distinct' => 'The same batch is on two lines.',
        ]);

        $this->validateBatchesBelong($validated);

        $return = $create->handle($validated, $request->user());

        return redirect()->route('purchasing.supplier-returns.show', $return)->with('success', "{$return->number} saved. Stock and the supplier balance are updated.");
    }

    public function show(SupplierReturn $supplierReturn): View
    {
        $this->authorize('view', $supplierReturn);

        $supplierReturn->load(['supplier', 'goodsReceipt', 'creator', 'lines.product.baseUnit', 'lines.variant', 'lines.batch']);

        return view('purchasing.supplier-returns.show', ['return' => $supplierReturn]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function validateBatchesBelong(array $validated): void
    {
        $batches = Batch::query()->whereIn('id', array_column($validated['lines'], 'batch_id'))->get()->keyBy('id');

        validator($validated, [])->after(function (Validator $validator) use ($validated, $batches): void {
            foreach ($validated['lines'] as $index => $line) {
                $batch = $batches->get((int) $line['batch_id']);
                $variantId = isset($line['variant_id']) && $line['variant_id'] !== '' ? (int) $line['variant_id'] : null;

                if ($batch === null || $batch->product_id !== (int) $line['product_id'] || $batch->variant_id !== $variantId) {
                    $validator->errors()->add("lines.{$index}.batch_id", 'This batch belongs to another product.');
                }
            }
        })->validate();
    }

    /**
     * Add each line's batches with stock, for the batch dropdown.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withBatches(array $rows): array
    {
        return array_map(function (array $row): array {
            $row['batches'] = StockLevel::query()
                ->with('batch')
                ->where('product_id', $row['product_id'])
                ->where('variant_id', $row['variant_id'])
                ->where('qty_on_hand', '>', 0)
                ->get()
                ->map(fn (StockLevel $level) => ['id' => $level->batch_id, 'label' => $level->batch->label(), 'available' => $level->qty_on_hand])
                ->values()
                ->all();

            return $row;
        }, $rows);
    }
}
