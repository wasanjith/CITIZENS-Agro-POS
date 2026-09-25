<?php

namespace App\Http\Controllers\Pos;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\PriceList;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Services\DiscountLimits;
use App\Domain\System\Services\Settings;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * GET /pos: the full-screen counter billing screen (Counters 1–3 and the main terminal).
 */
class CounterController extends Controller
{
    public function __invoke(Request $request, CurrentTerminal $currentTerminal, DiscountLimits $limits, Settings $settings): View
    {
        $terminal = $currentTerminal->get();
        $user = $request->user();
        $max = $limits->maxPercentFor($user);

        return view('pos.counter', [
            'terminal' => $terminal->loadMissing('printer'),
            'config' => [
                'terminal' => ['id' => $terminal->id, 'name' => $terminal->displayName(), 'is_main' => $terminal->isMainCashier(), 'counter_no' => $terminal->counter_no],
                'user' => ['id' => $user->id, 'name' => $user->name],
                'price_lists' => PriceList::query()->orderByDesc('is_default')->orderBy('id')->get(['id', 'name'])->all(),
                'default_price_list_id' => PriceList::default()?->id,
                'methods' => PaymentMethod::counterOptions(),
                'max_discount_percent' => $max !== null ? (string) $max : null,
                'categories' => Category::query()->whereNull('parent_id')->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'name_si'])->all(),
                'can_reprint' => $user->can('pos.reprint'),
                'can_add_customer' => $user->can('quickAdd', Customer::class),
                'can_settle' => $terminal->isMainCashier() && $user->can('pos.settle'),
                'urls' => [
                    'search' => route('api.pos.search'),
                    'cart' => route('api.pos.cart.show'),
                    'sync' => route('api.pos.cart.sync'),
                    'inbox' => route('api.pos.inbox'),
                    'quick' => route('api.pos.quick-items'),
                    'issue' => route('api.pos.invoices.store'),
                    'today' => route('api.pos.invoices.today'),
                    'holds' => route('api.pos.holds.index'),
                    'hold' => route('api.pos.holds.store'),
                    'approvals' => route('api.pos.approvals.store'),
                    'customers' => route('api.pos.customers.index'),
                    'customer' => url('/api/pos/customers/__ID__'),
                    'quotations' => route('api.pos.quotations.index'),
                    'quotation_cart' => url('/api/pos/quotations/__ID__/cart'),
                    'quotation_reprint' => url('/api/pos/quotations/__ID__/reprint'),
                    'cashier' => $terminal->isMainCashier() ? route('pos.cashier') : null,
                ],
                'invoice_language' => $settings->get('receipt.language'),
            ],
        ]);
    }
}
