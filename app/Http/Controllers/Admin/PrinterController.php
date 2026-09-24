<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\Printer;
use App\Domain\Identity\Models\Terminal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SavePrinterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PrinterController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Printer::class);

        return view('admin.printers.index', [
            'printers' => Printer::query()->with('terminal')->orderBy('name')->get(),
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
}
