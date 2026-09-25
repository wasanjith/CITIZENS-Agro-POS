<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Actions\RevokeDelegationAction;
use App\Domain\Identity\Models\Delegation;
use App\Domain\Identity\Services\CashierAuthority;
use App\Domain\Sales\Events\CashierAuthorityChanged;
use App\Domain\Sales\Support\LiveBroadcast;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /admin/delegations: the owner sees who holds cashier authority and can revoke it from
 * anywhere (e.g. their phone). The delegate's next settlement is blocked with
 * "Cashier authority revoked, count drawer".
 */
class DelegationController extends Controller
{
    public function index(Request $request, CashierAuthority $authority): View
    {
        abort_unless($request->user()->can('drawer.handover'), 403);

        return view('admin.delegations.index', [
            'active' => Delegation::query()->active()->with(['fromUser', 'toUser', 'drawerSession'])->latest('starts_at')->get(),
            'recent' => Delegation::query()->with(['fromUser', 'toUser', 'revokedBy'])->latest('starts_at')->limit(30)->get(),
            'holder' => $authority->holder(),
            'session' => $authority->openSession(),
        ]);
    }

    public function revoke(Request $request, Delegation $delegation, RevokeDelegationAction $revoke, CashierAuthority $authority): RedirectResponse
    {
        abort_unless($request->user()->can('drawer.handover'), 403);

        $revoke->handle($delegation, $request->user());

        activity()->performedOn($delegation)->causedBy($request->user())->event('revoked')
            ->log("Cashier authority of {$delegation->toUser->name} revoked");

        LiveBroadcast::send(new CashierAuthorityChanged($authority->holderSummary(), "{$request->user()->name} revoked the cashier authority of {$delegation->toUser->name}."));

        return back()->with('success', "Cashier authority of {$delegation->toUser->name} revoked. They must count and close the drawer.");
    }
}
