<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\Models\Tax;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TaxController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Tax::class);

        return view('catalog.taxes.index', [
            'taxes' => Tax::query()->withCount('products')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Tax::class);

        return view('catalog.taxes.form', ['tax' => new Tax(['is_active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Tax::class);

        $tax = Tax::create($this->validated($request));

        return redirect()->route('catalog.taxes.index')->with('success', "Tax {$tax->name} created.");
    }

    public function edit(Tax $tax): View
    {
        $this->authorize('update', $tax);

        return view('catalog.taxes.form', ['tax' => $tax]);
    }

    public function update(Request $request, Tax $tax): RedirectResponse
    {
        $this->authorize('update', $tax);

        $tax->update($this->validated($request));

        return redirect()->route('catalog.taxes.index')->with('success', "Tax {$tax->name} updated.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $request->merge(['is_active' => $request->boolean('is_active')]);

        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'is_active' => ['boolean'],
        ]);
    }
}
