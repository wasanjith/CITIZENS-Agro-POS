<?php

namespace App\Http\Middleware;

use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\Identity\Support\CurrentTerminal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settlement and drawer routes: only on the registered main cashier terminal, only for
 * the holder of its open drawer session, and only while they hold cashier authority
 * (pos.settle, possibly through a delegation).
 *
 *  - no drawer open        → "Open drawer" page (or 403 without drawer.manage)
 *  - drawer held by others → 403
 *  - authority revoked or expired → "count the drawer" page
 *
 * The open session is available as $request->attributes->get('drawerSession').
 */
class EnsureCashierSession
{
    public function __construct(private CurrentTerminal $currentTerminal) {}

    public function handle(Request $request, Closure $next): Response
    {
        $terminal = $this->currentTerminal->get();
        $user = $request->user();

        if ($terminal === null) {
            return $request->expectsJson()
                ? response()->json(['message' => 'This device is not a registered terminal.'], 403)
                : redirect()->route('terminal.unregistered');
        }

        if (! $terminal->isMainCashier()) {
            abort(403, 'Settlement is only available on the main cashier terminal.');
        }

        $session = DrawerSession::query()->where('terminal_id', $terminal->id)->open()->with('holder')->first();

        if ($session === null) {
            if (! $user->can('drawer.manage')) {
                abort(403, 'You do not hold cashier authority.');
            }

            return $this->redirectTo($request, route('pos.drawer.open'), 'Open the cash drawer first.');
        }

        if ($session->holder_user_id !== $user->id) {
            abort(403, "The drawer is held by {$session->holder->name}.");
        }

        if (! $user->can('pos.settle')) {
            return $this->redirectTo($request, route('pos.authority-ended'), 'Cashier authority revoked or expired. Count the drawer.');
        }

        $request->attributes->set('drawerSession', $session);

        return $next($request);
    }

    private function redirectTo(Request $request, string $url, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'redirect' => $url], 409);
        }

        return redirect()->to($url);
    }
}
