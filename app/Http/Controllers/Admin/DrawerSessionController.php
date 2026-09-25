<?php

namespace App\Http\Controllers\Admin;

use App\Domain\CashDrawer\Models\DrawerSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /admin/drawer-sessions: handover history (sessions, holders, times, variances).
 */
class DrawerSessionController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', DrawerSession::class);

        $filters = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        $sessions = DrawerSession::query()
            ->with(['holder', 'closer', 'terminal', 'delegation.fromUser', 'next.holder'])
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->whereDate('opened_at', '>=', $date))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->whereDate('opened_at', '<=', $date))
            ->latest('opened_at')
            ->paginate(30)
            ->withQueryString();

        return view('admin.drawer-sessions.index', ['sessions' => $sessions]);
    }
}
