<?php

namespace App\Http\Controllers\Pos;

use App\Domain\Customers\Actions\SaveCustomerAction;
use App\Domain\Customers\Models\Customer;
use App\Domain\System\Services\Settings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * F4 on the counter screen: find a customer by phone, name, NIC or village, or add one
 * quickly (name, phone, village; no credit until the Owner or Manager sets a limit).
 */
class CustomerController extends Controller
{
    /**
     * GET /api/pos/customers?q=
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $term = trim((string) $request->query('q', ''));

        $customers = Customer::query()
            ->active()
            ->search($term)
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json(['customers' => $customers->map(fn (Customer $customer) => $customer->posSummary())->all()]);
    }

    /**
     * GET /api/pos/customers/{customer}: balance, limit and overdue, for the bill and the settle card.
     */
    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        return response()->json(['customer' => $customer->posSummary()]);
    }

    /**
     * POST /api/pos/customers {name, phone, area}
     */
    public function store(Request $request, SaveCustomerAction $save, Settings $settings): JsonResponse
    {
        $this->authorize('quickAdd', Customer::class);

        $request->merge(['phone' => $save->normalisePhone($request->input('phone'))]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'name_si' => ['nullable', 'string', 'max:150'],
            'phone' => ['required', 'string', 'min:9', 'max:15', Rule::unique('customers', 'phone')->whereNull('deleted_at')],
            'area' => ['nullable', 'string', 'max:100'],
        ], [
            'phone.unique' => 'A customer with this phone number already exists. Search for it.',
        ]);

        $customer = $save->handle([
            ...$validated,
            'credit_limit' => 0,
            'credit_days' => (int) $settings->get('customers.default_credit_days', 30),
            'is_active' => true,
        ], $request->user());

        return response()->json(['customer' => $customer->posSummary()], 201);
    }
}
