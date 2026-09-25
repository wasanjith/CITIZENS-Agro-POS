<?php

namespace App\Http\Controllers\Pos;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\Sales\Actions\SettleInvoiceAction;
use App\Domain\Sales\Actions\VoidInvoiceAction;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\System\Services\Settings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * /pos/cashier: the Live Billing screen with settle cards, on the main terminal only,
 * for the holder of the open drawer who holds cashier authority ("cashier" middleware).
 */
class CashierController extends Controller
{
    public function __construct(private readonly CurrentTerminal $currentTerminal) {}

    public function index(Request $request, Settings $settings): View
    {
        $terminal = $this->currentTerminal->get()->loadMissing('printer');
        $session = $request->attributes->get('drawerSession');

        return view('pos.cashier', [
            'terminal' => $terminal,
            'session' => $session,
            'config' => [
                'mode' => 'cashier',
                'user' => ['id' => $request->user()->id, 'name' => $request->user()->name],
                'terminal_id' => $terminal->id,
                'printer' => $terminal->printer?->windows_name,
                'has_drawer' => (bool) $terminal->printer?->has_cash_drawer,
                'methods' => PaymentMethod::counterOptions(),
                'can_void' => $request->user()->can('pos.void'),
                'can_approve' => $request->user()->can('pos.approve_requests'),
                'can_override_credit' => $request->user()->hasRole(Role::SuperAdmin->value),
                'sound' => (bool) $settings->get('pos.invoice_sound', false),
                'focus_invoice' => $request->integer('invoice') ?: null,
                'urls' => [
                    'snapshot' => route('api.live-billing.snapshot'),
                    'find' => route('api.pos.sales.find'),
                    'settle' => url('/api/pos/sales/__ID__/settle'),
                    'void' => url('/pos/sales/__ID__/void'),
                    'approve' => url('/api/pos/approvals/__ID__/approve'),
                    'reject' => url('/api/pos/approvals/__ID__/reject'),
                    'invoice' => url('/pos/sales/__ID__/invoice'),
                    'sale' => url('/sales/__ID__'),
                    'customer' => url('/api/pos/customers/__ID__'),
                ],
            ],
        ]);
    }

    /**
     * POST /api/pos/sales/{sale}/settle {method?, reference?, idempotency_key}
     */
    public function settle(Request $request, Sale $sale, SettleInvoiceAction $settle): JsonResponse
    {
        $this->authorize('settle', $sale);

        $validated = $request->validate([
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
            'override_credit_limit' => ['nullable', 'boolean'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:64'],
        ]);

        $result = $settle->handle(
            $sale,
            $request->user(),
            $this->currentTerminal->get(),
            $request->attributes->get('drawerSession'),
            ['method' => $validated['method'] ?? null, 'reference' => $validated['reference'] ?? null, 'override_credit_limit' => (bool) ($validated['override_credit_limit'] ?? false)],
            $validated['idempotency_key'],
        );

        return response()->json([
            'sale' => $result['sale']->liveSummary(),
            'open_drawer' => $result['open_drawer'] && $result['created'],
            'created' => $result['created'],
            'credit_bill_url' => $result['credit_bill'] !== null ? route('pos.sales.credit-bill', ['sale' => $result['sale'], 'job' => $result['credit_bill']->id]) : null,
        ]);
    }

    /**
     * POST /pos/sales/{sale}/void {reason}: from the cashier screen (JSON) or the sale page (form).
     */
    public function void(Request $request, Sale $sale, VoidInvoiceAction $void): JsonResponse|RedirectResponse
    {
        $this->authorize('void', $sale);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $sale = $void->handle($sale, $request->user(), $validated['reason'], $request->attributes->get('drawerSession'));

        if ($request->expectsJson()) {
            return response()->json(['sale' => $sale->liveSummary()]);
        }

        return redirect()->route('sales.show', $sale)->with('success', "{$sale->invoice_no} voided.");
    }

    /**
     * GET /api/pos/sales/find?no=4512: the last digits of an invoice number.
     */
    public function find(Request $request): JsonResponse
    {
        $number = preg_replace('/\D/', '', (string) $request->query('no', ''));

        if ($number === '') {
            return response()->json(['sale' => null]);
        }

        $sale = Sale::query()
            ->with(['invoicedBy', 'invoicedTerminal', 'settledBy'])
            ->whereNotNull('invoice_no')
            ->where(fn ($query) => $query
                ->where('invoice_no', 'like', '%-'.str_pad(ltrim($number, '0'), 6, '0', STR_PAD_LEFT))
                ->orWhere('invoice_no', 'like', '%'.$number))
            ->orderByRaw('status = ? DESC', [SaleStatus::Invoiced->value])
            ->latest('invoiced_at')
            ->first();

        return response()->json(['sale' => $sale?->liveSummary()]);
    }
}
