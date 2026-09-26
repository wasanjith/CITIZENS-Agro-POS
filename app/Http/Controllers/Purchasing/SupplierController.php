<?php

namespace App\Http\Controllers\Purchasing;

use App\Domain\Finance\Services\FinancePosting;
use App\Domain\Purchasing\Models\Supplier;
use App\Http\Controllers\Concerns\HasListQuery;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SupplierController extends Controller
{
    use HasListQuery;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Supplier::class);

        $suppliers = $this->applyListQuery(
            Supplier::query()
                ->withSum('ledger as credits', 'credit')
                ->withSum('ledger as debits', 'debit'),
            $request,
            searchable: ['name', 'contact_person', 'phone'],
            sortable: ['name', 'created_at'],
            filters: [
                'status' => function (Builder $query, string $status): void {
                    $query->where('is_active', $status === 'active');
                },
            ],
            defaultSort: 'name',
            defaultDirection: 'asc',
        )->paginate(25)->withQueryString();

        return view('purchasing.suppliers.index', ['suppliers' => $suppliers]);
    }

    public function show(Supplier $supplier): View
    {
        $this->authorize('view', $supplier);

        return view('purchasing.suppliers.show', [
            'supplier' => $supplier,
            'balance' => $supplier->balance(),
            'orders' => $supplier->purchaseOrders()->latest('id')->limit(10)->get(),
            'receipts' => $supplier->goodsReceipts()->latest('id')->limit(10)->get(),
            'ledger' => $supplier->ledger()->with('creator')->orderByDesc('date')->orderByDesc('id')->paginate(20),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Supplier::class);

        return view('purchasing.suppliers.form', ['supplier' => new Supplier(['is_active' => true, 'payment_terms_days' => 30, 'opening_balance' => '0.00'])]);
    }

    public function store(Request $request, FinancePosting $finance): RedirectResponse
    {
        $this->authorize('create', Supplier::class);

        $supplier = DB::transaction(function () use ($request, $finance): Supplier {
            $supplier = Supplier::create($this->validated($request));
            $finance->supplierOpening($supplier, $request->user()->id);

            return $supplier;
        });

        return redirect()->route('purchasing.suppliers.show', $supplier)->with('success', "Supplier {$supplier->name} created.");
    }

    public function edit(Supplier $supplier): View
    {
        $this->authorize('update', $supplier);

        return view('purchasing.suppliers.form', ['supplier' => $supplier]);
    }

    public function update(Request $request, Supplier $supplier, FinancePosting $finance): RedirectResponse
    {
        $this->authorize('update', $supplier);

        DB::transaction(function () use ($request, $supplier, $finance): void {
            $supplier->update($this->validated($request, $supplier));

            if ($supplier->wasChanged('opening_balance')) {
                $finance->supplierOpening($supplier, $request->user()->id);
            }
        });

        return redirect()->route('purchasing.suppliers.show', $supplier)->with('success', "Supplier {$supplier->name} updated.");
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $this->authorize('delete', $supplier);

        if ($supplier->purchaseOrders()->exists() || $supplier->goodsReceipts()->exists()) {
            return back()->with('error', "{$supplier->name} has purchase orders or goods receipts. Mark it inactive instead.");
        }

        $supplier->delete();

        return redirect()->route('purchasing.suppliers.index')->with('success', "Supplier {$supplier->name} deleted.");
    }

    /**
     * GET /api/purchasing/suppliers?q= → [{ id, label, hint }] for <x-ui.search-select>.
     */
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('lookup', Supplier::class);

        $term = trim((string) $request->query('q', ''));

        $suppliers = Supplier::query()
            ->active()
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")))
            ->orderBy('name')
            ->limit(15)
            ->get();

        return response()->json($suppliers->map(fn (Supplier $supplier) => [
            'id' => $supplier->id,
            'label' => $supplier->name,
            'hint' => $supplier->phone,
        ])->values());
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        $request->merge([
            'is_active' => $request->boolean('is_active'),
            'opening_balance' => $request->input('opening_balance') ?: 0,
            'payment_terms_days' => $request->input('payment_terms_days') ?: 0,
        ]);

        return $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('suppliers', 'name')->ignore($supplier?->id)->whereNull('deleted_at')],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'payment_terms_days' => ['integer', 'min:0', 'max:365'],
            'opening_balance' => ['numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'is_active' => ['boolean'],
        ]);
    }
}
