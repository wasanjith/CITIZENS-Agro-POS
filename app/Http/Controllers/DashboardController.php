<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Services\CashierAuthority;
use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\Reports\Services\DashboardData;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Home page. The owner sees the whole shop at a glance (sales, profit, payments, stock,
 * purchases, supplier bills, cash); a Manager sees sales, stock and purchasing; staff see
 * their device and shortcuts.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, CurrentTerminal $currentTerminal, CashierAuthority $cashierAuthority, DashboardData $dashboard): View
    {
        return view('dashboard', [
            'user' => $request->user(),
            'terminal' => $currentTerminal->get(),
            'cashierHolder' => $cashierAuthority->holder(),
            'activeDelegation' => $cashierAuthority->activeDelegation(),
            'data' => $dashboard->for($request->user()),
        ]);
    }
}
