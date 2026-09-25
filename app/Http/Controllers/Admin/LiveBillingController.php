<?php

namespace App\Http\Controllers\Admin;

use App\Domain\System\Services\Settings;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /admin/live-billing: the three counters in real time, view only (owner's dashboard
 * and phone). The cashier screen uses the same component with settle buttons.
 */
class LiveBillingController extends Controller
{
    public function __invoke(Request $request, Settings $settings): View
    {
        return view('admin.live-billing', [
            'config' => [
                'mode' => 'view',
                'user' => ['id' => $request->user()->id, 'name' => $request->user()->name],
                'sound' => (bool) $settings->get('pos.invoice_sound', false),
                'urls' => [
                    'snapshot' => route('api.live-billing.snapshot'),
                    'sale' => url('/sales/__ID__'),
                ],
            ],
        ]);
    }
}
