<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Services\BrandSupplierLinker;
use App\Domain\Purchasing\Models\Supplier;
use App\Http\Controllers\Concerns\HasListQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                Brand::query()->with('supplier')->withCount('products'),
                $request,
                searchable: ['name', 'supplier.name'],
                sortable: ['name', 'products_count'],
                defaultSort: 'name',
                defaultDirection: 'asc',
            )->paginate(50)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Brand::class);

        return view('catalog.brands.form', ['brand' => new Brand(['is_active' => true]), 'suppliers' => $this->supplierOptions()]);
    }

    public function store(Request $request, BrandSupplierLinker $linker): RedirectResponse
    {
        $this->authorize('create', Brand::class);

        $brand = DB::transaction(function () use ($request, $linker): Brand {
            $brand = Brand::create($this->validated($request));
            $linker->linkBrand($brand);

            return $brand;
        });

        return redirect()->route('catalog.brands.index')->with('success', "Brand {$brand->name} created.");
    }

    public function edit(Brand $brand): View
    {
        $this->authorize('update', $brand);

        return view('catalog.brands.form', ['brand' => $brand, 'suppliers' => $this->supplierOptions($brand)]);
    }

    public function update(Request $request, Brand $brand, BrandSupplierLinker $linker): RedirectResponse
    {
        $this->authorize('update', $brand);

        DB::transaction(function () use ($request, $brand, $linker): void {
            $previousSupplierId = $brand->supplier_id;
            $brand->update($this->validated($request, $brand));

            // A brand has one supplier: its products move to the new one.
            if ($brand->wasChanged('supplier_id')) {
                $linker->linkBrand($brand, $previousSupplierId);
            }
        });

        // The brand name is in each product's search document.
        if ($brand->wasChanged('name')) {
            foreach ($brand->products()->get() as $product) {
                $product->searchable();
            }
        }

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
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'is_active' => ['boolean'],
        ], [
            'supplier_id.required' => 'Choose the supplier this brand is bought from.',
        ]);
    }

    /**
     * Active suppliers, plus the brand's current one if it has been made inactive.
     *
     * @return array<int, string>
     */
    private function supplierOptions(?Brand $brand = null): array
    {
        return Supplier::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $brand?->supplier_id))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
