<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\Models\Brand;
use App\Http\Controllers\Concerns\HasListQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BrandController extends Controller
{
    use HasListQuery;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Brand::class);

        return view('catalog.brands.index', [
            'brands' => $this->applyListQuery(
                Brand::query()->withCount('products'),
                $request,
                searchable: ['name'],
                sortable: ['name', 'products_count'],
                defaultSort: 'name',
                defaultDirection: 'asc',
            )->paginate(50)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Brand::class);

        return view('catalog.brands.form', ['brand' => new Brand(['is_active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Brand::class);

        $brand = Brand::create($this->validated($request));

        return redirect()->route('catalog.brands.index')->with('success', "Brand {$brand->name} created.");
    }

    public function edit(Brand $brand): View
    {
        $this->authorize('update', $brand);

        return view('catalog.brands.form', ['brand' => $brand]);
    }

    public function update(Request $request, Brand $brand): RedirectResponse
    {
        $this->authorize('update', $brand);

        $brand->update($this->validated($request, $brand));

        return redirect()->route('catalog.brands.index')->with('success', "Brand {$brand->name} updated.");
    }

    public function destroy(Brand $brand): RedirectResponse
    {
        $this->authorize('delete', $brand);

        if ($brand->products()->exists()) {
            return back()->with('error', "{$brand->name} is used by products. Mark it inactive instead.");
        }

        $brand->delete();

        return redirect()->route('catalog.brands.index')->with('success', "Brand {$brand->name} deleted.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Brand $brand = null): array
    {
        $request->merge(['is_active' => $request->boolean('is_active')]);

        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('brands', 'name')->ignore($brand?->id)->whereNull('deleted_at')],
            'is_active' => ['boolean'],
        ]);
    }
}
