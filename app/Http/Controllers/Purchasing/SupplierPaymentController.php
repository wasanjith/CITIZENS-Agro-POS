<?php

namespace App\Http\Controllers\Purchasing;

use App\Domain\Finance\Enums\PaidFrom;
use App\Domain\Finance\Models\BankAccount;
use App\Domain\Finance\Services\DrawerCash;
use App\Domain\Finance\Services\FinancePosting;
use App\Domain\Purchasing\Actions\PaySupplierAction;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Models\SupplierPayment;
use App\Domain\Sales\Support\Money;
use App\Http\Controllers\Concerns\HasListQuery;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Payments to suppliers (cash, bank or cheque), applied to their goods receipts.
 */
class SupplierPaymentController extends Controller
{
    use HasListQuery;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', SupplierPayment::class);

        $payments = $this->applyListQuery(
            SupplierPayment::query()->with(['supplier', 'createdBy', 'cheque']),
            $request,
            searchable: ['number', 'reference'],
            sortable: ['date', 'number', 'amount'],
            filters: [
                'supplier' => function (Builder $query, string $supplier): void {
                    $query->where('supplier_id', (int) $supplier);
                },
                'paid_from' => function (Builder $query, string $from): void {
                    $query->where('paid_from', $from);
                },
            ],
            defaultSort: 'date',
        )->orderByDesc('id')->paginate(50)->withQueryString();

        return view('purchasing.supplier-payments.index', ['payments' => $payments, 'sources' => PaidFrom::supplierOptions()]);
    }

    public function create(Request $request, DrawerCash $drawerCash): View
    {
        $this->authorize('create', SupplierPayment::class);

        $supplier = $request->filled('supplier') ? Supplier::query()->find($request->integer('supplier')) : null;

        return view('purchasing.supplier-payments.create', [
            'supplier' => $supplier,
            'balance' => $supplier?->balance(),
            'openReceipts' => $supplier?->unpaidReceipts()->get() ?? collect(),
            'sources' => PaidFrom::supplierOptions(),
            'banks' => BankAccount::options(),
            'drawerOpen' => $drawerCash->openSession() !== null,
        ]);
    }

    public function store(Request $request, Supplier $supplier, PaySupplierAction $pay): RedirectResponse
    {
        $this->authorize('create', SupplierPayment::class);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'paid_from' => ['required', Rule::enum(PaidFrom::class)],
            'bank_account_id' => ['nullable', 'integer'],
            'cheque_number' => ['nullable', 'string', 'max:30'],
            'cheque_date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
            'allocation_mode' => ['required', 'in:fifo,manual'],
            'allocations' => ['nullable', 'array'],
            'allocations.*' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ]);

        if ($validated['paid_from'] === PaidFrom::CashDrawer->value) {
            $validated['date'] = today()->toDateString();
        }

        $payment = $pay->handle($supplier, [
            ...$validated,
            'amount' => (string) $validated['amount'],
            'allocations' => $validated['allocation_mode'] === 'manual' ? array_filter($validated['allocations'] ?? [], fn ($value) => $value !== null && $value !== '') : null,
        ], $request->user());

        return redirect()->route('purchasing.supplier-payments.show', $payment)->with('success', "{$payment->number}: Rs. ".Money::format($payment->amount)." paid to {$supplier->name}.");
    }

    public function show(SupplierPayment $supplierPayment, FinancePosting $posting): View
    {
        $this->authorize('view', $supplierPayment);

        return view('purchasing.supplier-payments.show', [
            'payment' => $supplierPayment->load(['supplier', 'allocations.goodsReceipt', 'createdBy', 'cheque', 'bankAccount']),
            'entries' => request()->user()->can('finance.journal.view') ? $posting->entriesFor($supplierPayment) : collect(),
        ]);
    }
}
