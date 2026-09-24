<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\Terminal;
use App\Domain\Identity\Services\TerminalRegistrar;
use App\Domain\Identity\Support\CurrentTerminal;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

/**
 * Registers the browser the Super Admin is using as the given terminal.
 */
class TerminalDeviceController extends Controller
{
    public function show(Terminal $terminal, CurrentTerminal $currentTerminal): View
    {
        $this->authorize('register', $terminal);

        return view('admin.terminals.register', [
            'terminal' => $terminal,
            'currentTerminal' => $currentTerminal->get(),
        ]);
    }

    public function store(Request $request, Terminal $terminal, TerminalRegistrar $registrar): RedirectResponse
    {
        $this->authorize('register', $terminal);

        $token = $registrar->register($terminal, $request->user());

        Cookie::queue(Cookie::make(
            name: config('pos.device_cookie'),
            value: $token,
            minutes: config('pos.device_cookie_minutes'),
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: 'lax',
        ));

        activity()->performedOn($terminal)->event('device_registered')->log("This device was registered as {$terminal->name}");

        return redirect()->route('admin.terminals.index')
            ->with('success', "This device is now registered as {$terminal->name}. Any device previously registered as {$terminal->name} has been signed off.");
    }

    public function destroy(Terminal $terminal, TerminalRegistrar $registrar, CurrentTerminal $currentTerminal): RedirectResponse
    {
        $this->authorize('register', $terminal);

        $isThisDevice = $currentTerminal->get()?->is($terminal) ?? false;

        $registrar->unregister($terminal);
        activity()->performedOn($terminal)->event('device_unregistered')->log("Device unregistered from {$terminal->name}");

        if ($isThisDevice) {
            Cookie::queue(Cookie::forget(config('pos.device_cookie')));
        }

        return redirect()->route('admin.terminals.index')->with('success', "{$terminal->name} no longer has a registered device.");
    }
}
