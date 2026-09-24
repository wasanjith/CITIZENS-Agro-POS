<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Enums\TerminalType;
use App\Domain\Identity\Support\CurrentTerminal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only allows requests from a registered terminal, optionally of one type.
 *
 * Usage: 'terminal', 'terminal:main_cashier', 'terminal:counter'.
 */
class EnsureRegisteredTerminal
{
    public function __construct(private CurrentTerminal $currentTerminal) {}

    public function handle(Request $request, Closure $next, ?string $type = null): Response
    {
        $terminal = $this->currentTerminal->get();

        if ($terminal === null) {
            if ($request->expectsJson()) {
                abort(403, 'This device is not a registered terminal.');
            }

            return redirect()->route('terminal.unregistered');
        }

        if ($type !== null && $terminal->type !== TerminalType::from($type)) {
            abort(403, 'This action is not available on '.$terminal->displayName().'.');
        }

        return $next($request);
    }
}
