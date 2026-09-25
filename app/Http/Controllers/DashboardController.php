<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Models\Printer;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Identity\Services\CashierAuthority;
use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, CurrentTerminal $currentTerminal, CashierAuthority $cashierAuthority): View
    {
        return view('dashboard', [
            'user' => $request->user(),
            'terminal' => $currentTerminal->get(),
            'cashierHolder' => $cashierAuthority->holder(),
            'activeDelegation' => $cashierAuthority->activeDelegation(),
            'today' => $request->user()->can('pos.live_view') ? [
                'total' => Sale::query()->where('status', SaleStatus::Settled)->where('settled_at', '>=', today())->sum('total'),
                'count' => Sale::query()->where('status', SaleStatus::Settled)->where('settled_at', '>=', today())->count(),
                'waiting' => Sale::query()->where('status', SaleStatus::Invoiced)->count(),
                'voids' => Sale::query()->where('status', SaleStatus::Void)->where('voided_at', '>=', today())->count(),
            ] : null,
            'stats' => [
                'users' => User::query()->active()->count(),
                'terminals' => Terminal::query()->active()->count(),
                'registeredTerminals' => Terminal::query()->active()->whereNotNull('device_token_hash')->count(),
                'printers' => Printer::query()->where('is_active', true)->count(),
            ],
        ]);
    }
}
