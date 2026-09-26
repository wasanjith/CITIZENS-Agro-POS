<?php

namespace App\Http\Controllers\Customers;

use App\Domain\Customers\Actions\ReceiveCustomerPaymentAction;
use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Models\CustomerPayment;
use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\PrintDocumentType;
use App\Domain\Sales\Models\PrintJob;
use App\Http\Controllers\Concerns\HasListQuery;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Customer payments: taken at the main cashier (drawer holder with cashier authority),
 * listed and reprinted in the back office.
 */
class CustomerPaymentController extends Controller
{
    use HasListQuery;

    public function __construct(private readonly CurrentTerminal $currentTerminal) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', CustomerPayment::class);

        $payments = $this->applyListQuery(
            CustomerPayment::query()->with(['customer', 'receivedBy']),
            $request,
            searchable: ['number', 'reference'],
            sortable: ['number', 'created_at', 'amount'],
            filters: [
                'method' => function (Builder $query, string $method): void {
                    $query->where('method', $method);
                },
                'date' => function (Builder $query, string $date): void {
                    $query->whereDate('date', $date);
                },
            ],
            defaultSort: 'created_at',
        )->paginate(50)->withQueryString();

        return view('customers.payments.index', ['payments' => $payments, 'methods' => PaymentMethod::customerPaymentOptions()]);
    }

    public function show(CustomerPayment $customerPayment): View
    {
        $this->authorize('view', $customerPayment);

        return view('customers.payments.show', [
            'payment' => $customerPayment->load(['customer', 'allocations.sale', 'receivedBy', 'terminal']),
            'canReprint' => $this->currentTerminal->get()?->isMainCashier() ?? false,
        ]);
    }

    /**
     * GET /pos/customer-payment?customer=: the form at the main cashier.
     */
    public function create(Request $request): View
    {
        $this->authorize('create', CustomerPayment::class);

        $customer = $request->filled('customer') ? Customer::query()->find($request->integer('customer')) : null;

        return view('customers.payments.create', [
            'customer' => $customer,
            'balance' => $customer?->balance(),
            'openInvoices' => $customer?->openCreditSales()->get() ?? collect(),
            'methods' => PaymentMethod::customerPaymentOptions(),
            'idempotencyKey' => str()->random(40),
        ]);
    }

    public function store(Request $request, Customer $customer, ReceiveCustomerPaymentAction $receive): RedirectResponse
    {
        $this->authorize('receivePayment', $customer);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'method' => ['required', Rule::in(array_keys(PaymentMethod::customerPaymentOptions()))],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
            'cheque_bank' => ['nullable', 'string', 'max:100'],
            'cheque_branch' => ['nullable', 'string', 'max:100'],
            'cheque_date' => ['nullable', 'date'],
            'allocation_mode' => ['required', 'in:fifo,manual'],
            'allocations' => ['nullable', 'array'],
            'allocations.*' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:64'],
        ]);

        $result = $receive->handle(
            $customer,
            $request->user(),
            $this->currentTerminal->get(),
            $request->attributes->get('drawerSession'),
            [
                'amount' => (string) $validated['amount'],
                'method' => $validated['method'],
                'reference' => $validated['reference'] ?? null,
                'note' => $validated['note'] ?? null,
                'cheque_bank' => $validated['cheque_bank'] ?? null,
                'cheque_branch' => $validated['cheque_branch'] ?? null,
                'cheque_date' => $validated['cheque_date'] ?? null,
                'allocations' => $validated['allocation_mode'] === 'manual' ? array_filter($validated['allocations'] ?? [], fn ($value) => $value !== null && $value !== '') : null,
            ],
            $validated['idempotency_key'],
        );

        $payment = $result['payment'];
        $redirect = redirect()->route('customers.payments.show', $payment)->with('success', "{$payment->number}: Rs. ".number_format((float) $payment->amount, 2)." received from {$customer->name}.");

        if ($result['print_job'] !== null) {
            $redirect->with('print_url', route('pos.customer-payments.receipt', ['customerPayment' => $payment, 'job' => $result['print_job']->id]));
        }

        return $redirect;
    }

    /**
     * POST /pos/customer-payments/{payment}/reprint (main terminal): a COPY on printer #0.
     */
    public function reprint(Request $request, CustomerPayment $customerPayment): RedirectResponse
    {
        $this->authorize('view', $customerPayment);

        $job = PrintJob::record(PrintDocumentType::PaymentReceipt, $customerPayment->id, $this->currentTerminal->get()->loadMissing('printer'), $request->user()->id, isCopy: true);

        return back()->with('print_url', route('pos.customer-payments.receipt', ['customerPayment' => $customerPayment, 'job' => $job->id]));
    }
}
