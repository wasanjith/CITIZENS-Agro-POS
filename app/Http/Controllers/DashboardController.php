<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Models\Printer;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Identity\Services\CashierAuthority;
use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\Reports\Services\DashboardData;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Home page. The owner sees the whole shop at a glance (sales, cash, approvals, stock,
 * cheques, balances); a Manager sees stock and purchasing; staff see their shortcuts.
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
            'stats' => [
                'users' => User::query()->active()->count(),
                'terminals' => Terminal::query()->active()->count(),
                'registeredTerminals' => Terminal::query()->active()->whereNotNull('device_token_hash')->count(),
                'printers' => Printer::query()->where('is_active', true)->count(),
            ],
        ]);
    }
}
