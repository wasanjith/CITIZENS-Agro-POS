<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\Models\Unit;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UnitController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Unit::class);

        return view('catalog.units.index', [
            'units' => Unit::query()->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Unit::class);

        return view('catalog.units.form', ['unit' => new Unit(['allows_decimal' => false])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Unit::class);

        $unit = Unit::create($this->validated($request));

        return redirect()->route('catalog.units.index')->with('success', "Unit {$unit->name} created.");
    }

    public function edit(Unit $unit): View
    {
        $this->authorize('update', $unit);

        return view('catalog.units.form', ['unit' => $unit]);
    }

    public function update(Request $request, Unit $unit): RedirectResponse
    {
        $this->authorize('update', $unit);

        $unit->update($this->validated($request, $unit));

        return redirect()->route('catalog.units.index')->with('success', "Unit {$unit->name} updated.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Unit $unit = null): array
    {
        $request->merge([
            'name' => mb_strtolower(trim((string) $request->input('name'))),
            'allows_decimal' => $request->boolean('allows_decimal'),
        ]);

        return $request->validate([
            'name' => ['required', 'string', 'max:40', Rule::unique('units', 'name')->ignore($unit?->id)],
            'name_si' => ['nullable', 'string', 'max:40'],
            'symbol' => ['required', 'string', 'max:10'],
            'allows_decimal' => ['boolean'],
        ]);
    }
}
