<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Services\TerminalRegistrar;
use App\Domain\Identity\Support\CurrentTerminal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifies the registered terminal (if any) from the device cookie.
 * Never blocks the request; see EnsureRegisteredTerminal for that.
 */
class ResolveTerminal
{
    public function __construct(
        private TerminalRegistrar $registrar,
        private CurrentTerminal $currentTerminal,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie(config('pos.device_cookie'));
        $terminal = $this->registrar->resolve(is_string($token) ? $token : null);

        if ($terminal !== null && ($terminal->last_seen_at === null || $terminal->last_seen_at->lt(now()->subMinutes(5)))) {
            $terminal->timestamps = false;
            $terminal->forceFill(['last_seen_at' => now()])->saveQuietly();
            $terminal->timestamps = true;
        }

        $this->currentTerminal->set($terminal);

        return $next($request);
    }
}
