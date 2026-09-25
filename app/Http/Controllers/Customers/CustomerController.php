<?php

namespace App\Http\Controllers\Customers;

use App\Domain\Catalog\Models\PriceList;
use App\Domain\Customers\Actions\SaveCustomerAction;
use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Services\CustomerStatement;
use App\Domain\Sales\Support\InvoicePresenter;
use App\Domain\System\Services\Settings;
use App\Http\Controllers\Concerns\HasListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\SaveCustomerRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Customer list, profile (balance, unpaid invoices, ageing, ledger), statement PDF and
 * the credit ageing report.
 */
class CustomerController extends Controller
{
    use HasListQuery;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Customer::class);

        $customers = $this->applyListQuery(
            Customer::query()
                ->with('priceList')
                ->withSum('ledger as debits', 'debit')
                ->withSum('ledger as credits', 'credit')
                ->when(trim((string) $request->query('search', '')) !== '', fn (Builder $query) => $query->search((string) $request->query('search'))),
            $request,
            sortable: ['name', 'code', 'created_at'],
            filters: [
                'status' => function (Builder $query, string $status): void {
                    $query->where('is_active', $status === 'active');
                },
                'area' => function (Builder $query, string $area): void {
                    $query->where('area', $area);
                },
                'owing' => function (Builder $query, string $owing): void {
                    if ($owing === '1') {
                        $query->whereHas('sales', fn (Builder $sales) => $sales->whereIn('status', Customer::OPEN_SALE_STATUSES)->where('balance_due', '>', 0));
                    }
                },
                'overdue' => function (Builder $query, string $overdue): void {
                    if ($overdue === '1') {
                        $query->whereHas('sales', fn (Builder $sales) => $sales->whereIn('status', Customer::OPEN_SALE_STATUSES)->where('balance_due', '>', 0)->where('due_date', '<', today()));
                    }
                },
            ],
            defaultSort: 'name',
            defaultDirection: 'asc',
        )->paginate(25)->withQueryString();

        return view('customers.index', [
            'customers' => $customers,
            'areas' => Customer::query()->whereNotNull('area')->distinct()->orderBy('area')->pluck('area', 'area')->all(),
        ]);
    }

    public function show(Customer $customer, CustomerStatement $statements): View
    {
        $this->authorize('view', $customer);

        $open = $customer->openCreditSales()->get();

        return view('customers.show', [
            'customer' => $customer->load('priceList'),
            'balance' => $customer->balance(),
            'openInvoices' => $open,
            'ageing' => $statements->ageing($open),
            'ledger' => $customer->ledger()->with('creator')->orderByDesc('date')->orderByDesc('id')->paginate(20),
            'payments' => $customer->payments()->with('receivedBy')->latest('id')->limit(10)->get(),
            'recentSales' => $customer->sales()->whereNotNull('invoice_no')->latest('id')->limit(10)->get(),
        ]);
    }

    public function create(Settings $settings): View
    {
        $this->authorize('create', Customer::class);

        return view('customers.form', [
            'customer' => new Customer(['is_active' => true, 'credit_limit' => '0.00', 'credit_days' => (int) $settings->get('customers.default_credit_days', 30)]),
            'priceLists' => PriceList::query()->orderByDesc('is_default')->orderBy('id')->pluck('name', 'id')->all(),
        ]);
    }

    public function store(SaveCustomerRequest $request, SaveCustomerAction $save): RedirectResponse
    {
        $this->authorize('create', Customer::class);

        $customer = $save->handle($request->customerData(withOpeningBalance: true), $request->user());

        return redirect()->route('customers.show', $customer)->with('success', "Customer {$customer->name} ({$customer->code}) created.");
    }

    public function edit(Customer $customer): View
    {
        $this->authorize('update', $customer);

        return view('customers.form', [
            'customer' => $customer,
            'priceLists' => PriceList::query()->orderByDesc('is_default')->orderBy('id')->pluck('name', 'id')->all(),
        ]);
    }

    public function update(SaveCustomerRequest $request, Customer $customer, SaveCustomerAction $save): RedirectResponse
    {
        $this->authorize('update', $customer);

        $save->handle($request->customerData(withOpeningBalance: false), $request->user(), $customer);

        return redirect()->route('customers.show', $customer)->with('success', "Customer {$customer->name} updated.");
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $this->authorize('delete', $customer);

        if ($customer->ledger()->exists() || $customer->sales()->exists()) {
            return back()->with('error', "{$customer->name} has invoices or account entries. Mark the customer inactive instead.");
        }

        $customer->delete();

        return redirect()->route('customers.index')->with('success', "Customer {$customer->name} deleted.");
    }

    /**
     * GET /customers/{customer}/statement.pdf?from=&to=&lang=
     */
    public function statement(Request $request, Customer $customer, CustomerStatement $statements, InvoicePresenter $presenter, Settings $settings): PdfBuilder
    {
        $this->authorize('view', $customer);

        [$from, $to] = $this->period($request);

        return Pdf::view('print.customer-statement', [
            'statement' => $statements->build($customer, $from, $to),
            'language' => $presenter->language(null, $request->query('lang')),
            'shop' => $settings->group('shop'),
        ])->format('a4')->name("{$customer->code}-statement.pdf");
    }

    /**
     * GET /customers-ageing: every customer who owes, by how long it is overdue.
     */
    public function ageing(CustomerStatement $statements): View
    {
        $this->authorize('viewAny', Customer::class);

        $rows = $statements->ageingAll();
        $customers = Customer::query()->withTrashed()->whereIn('id', $rows->pluck('customer_id'))->get()->keyBy('id');

        return view('customers.ageing', [
            'rows' => $rows->sortByDesc(fn (array $row) => (float) $row['total'])->values(),
            'customers' => $customers,
            'labels' => CustomerStatement::bucketLabels(),
        ]);
    }

    /**
     * GET /api/customers?q= → [{ id, label, hint }] for <x-ui.search-select>.
     */
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $customers = Customer::query()->active()->search((string) $request->query('q', ''))->orderBy('name')->limit(15)->get();

        return response()->json($customers->map(fn (Customer $customer) => [
            'id' => $customer->id,
            'label' => "{$customer->name} ({$customer->code})",
            'hint' => trim(($customer->phone ?? '').' '.($customer->area ?? '')),
        ])->values());
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return [
            isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : today()->subMonths(3)->startOfMonth(),
            isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : today()->endOfDay(),
        ];
    }
}
