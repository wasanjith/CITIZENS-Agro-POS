<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\Printer;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\Sales\Enums\PrintDocumentType;
use App\Domain\Sales\Events\TestPrintRequested;
use App\Domain\Sales\Models\PrintJob;
use App\Domain\Sales\Support\LiveBroadcast;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SavePrinterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PrinterController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Printer::class);

        return view('admin.printers.index', [
            'printers' => Printer::query()->with('terminal')->orderBy('name')->get(),
            'terminals' => Terminal::query()->active()->orderByRaw('counter_no IS NULL, counter_no')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Printer::class);

        return view('admin.printers.form', [
            'printer' => new Printer(['paper_width_mm' => 80, 'dpi' => 203, 'is_active' => true]),
            'terminals' => Terminal::query()->orderBy('name')->get(),
        ]);
    }

    public function store(SavePrinterRequest $request): RedirectResponse
    {
        $printer = Printer::create($request->validated());

        return redirect()->route('admin.printers.index')->with('success', "Printer {$printer->name} added.");
    }

    public function edit(Printer $printer): View
    {
        $this->authorize('update', $printer);

        return view('admin.printers.form', [
            'printer' => $printer,
            'terminals' => Terminal::query()->orderBy('name')->get(),
        ]);
    }

    public function update(SavePrinterRequest $request, Printer $printer): RedirectResponse
    {
        $printer->update($request->validated());

        return redirect()->route('admin.printers.index')->with('success', "Printer {$printer->name} updated.");
    }

    /**
     * POST /admin/printers/{printer}/assign {terminal_id}: move a printer to a terminal
     * (e.g. a spare to Counter 2 when its printer fails). The terminal's current printer
     * becomes a spare.
     */
    public function assign(Request $request, Printer $printer): RedirectResponse
    {
        $this->authorize('update', $printer);

        $validated = $request->validate(['terminal_id' => ['nullable', 'integer', 'exists:terminals,id']]);
        $terminal = isset($validated['terminal_id']) ? Terminal::query()->find($validated['terminal_id']) : null;

        DB::transaction(function () use ($printer, $terminal): void {
            if ($terminal !== null) {
                Printer::query()->where('terminal_id', $terminal->id)->whereKeyNot($printer->id)->get()
                    ->each(fn (Printer $other) => $other->update(['terminal_id' => null]));
            }

            $printer->update(['terminal_id' => $terminal?->id]);
        });

        return back()->with('success', $terminal !== null
            ? "{$printer->name} now prints for {$terminal->displayName()}. Run a test print on that PC."
            : "{$printer->name} is now a spare.");
    }

    /**
     * POST /admin/printers/{printer}/test: ask the printer's terminal to print its test page.
     * The terminal (counter or main screen open) receives it over WebSockets, prints it
     * silently and reports back, which sets "last test".
     */
    public function test(Request $request, Printer $printer, CurrentTerminal $currentTerminal): RedirectResponse
    {
        $this->authorize('update', $printer);

        $terminal = $printer->terminal;

        if ($terminal === null) {
            return back()->withErrors(['printer' => "{$printer->name} is not assigned to a terminal."]);
        }

        $job = PrintJob::record(PrintDocumentType::Test, $printer->id, $terminal->loadMissing('printer'), $request->user()->id);
        $url = route('pos.printers.test-page', ['job' => $job->id]);

        LiveBroadcast::send(new TestPrintRequested($terminal->id, $url));

        $here = $currentTerminal->get()?->is($terminal);

        return back()
            ->with('success', $here
                ? "Printing the test page on {$printer->name}…"
                : "Test print sent to {$terminal->displayName()}. It prints when the POS screen is open there.")
            ->with('print_url', $here ? $url : null);
    }
}
