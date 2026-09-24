<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Enums\TerminalType;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Identity\Support\CurrentTerminal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveTerminalRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TerminalController extends Controller
{
    public function index(CurrentTerminal $currentTerminal): View
    {
        $this->authorize('viewAny', Terminal::class);

        return view('admin.terminals.index', [
            'terminals' => Terminal::query()
                ->with(['printer', 'registeredBy'])
                ->orderByRaw('type = ? desc', [TerminalType::MainCashier->value])
                ->orderBy('counter_no')
                ->orderBy('name')
                ->get(),
            'currentTerminal' => $currentTerminal->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Terminal::class);

        return view('admin.terminals.form', [
            'terminal' => new Terminal(['type' => TerminalType::Counter, 'is_active' => true]),
            'types' => TerminalType::cases(),
        ]);
    }

    public function store(SaveTerminalRequest $request): RedirectResponse
    {
        $terminal = Terminal::create($request->validated());

        return redirect()->route('admin.terminals.index')->with('success', "Terminal {$terminal->name} created.");
    }

    public function edit(Terminal $terminal): View
    {
        $this->authorize('update', $terminal);

        return view('admin.terminals.form', [
            'terminal' => $terminal,
            'types' => TerminalType::cases(),
        ]);
    }

    public function update(SaveTerminalRequest $request, Terminal $terminal): RedirectResponse
    {
        $terminal->update($request->validated());

        return redirect()->route('admin.terminals.index')->with('success', "Terminal {$terminal->name} updated.");
    }
}
