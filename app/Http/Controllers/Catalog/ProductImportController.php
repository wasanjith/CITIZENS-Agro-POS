<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\Import\ProductImportColumns;
use App\Domain\Catalog\Import\ProductImporter;
use App\Domain\Catalog\Import\ProductImportTemplate;
use App\Domain\Catalog\Import\ProductsExport;
use App\Domain\Catalog\Models\Product;
use App\Http\Controllers\Concerns\HasListQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Excel import (template → upload → preview → import) and export of the product list.
 */
class ProductImportController extends Controller
{
    use HasListQuery;

    private const DIRECTORY = 'imports';

    public function create(): View
    {
        $this->authorize('import', Product::class);

        return view('catalog.products.import', ['columns' => ProductImportColumns::all()]);
    }

    public function template(): BinaryFileResponse
    {
        $this->authorize('import', Product::class);

        return (new ProductImportTemplate)->download('product-import-template.xlsx');
    }

    /**
     * Upload and check the file. Nothing is saved yet.
     */
    public function preview(Request $request, ProductImporter $importer): View|RedirectResponse
    {
        $this->authorize('import', Product::class);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $token = (string) Str::uuid();
        $path = $request->file('file')->storeAs(self::DIRECTORY, "{$token}.".$request->file('file')->getClientOriginalExtension(), 'local');
        $request->session()->put("catalog_import.{$token}", $path);

        $result = $importer->check($importer->read(Storage::disk('local')->path($path)));

        if ($result['valid'] === [] && $result['errors'] === [] && $result['missing_columns'] === []) {
            return back()->with('error', 'The file has no product rows.');
        }

        return view('catalog.products.import-preview', [
            'token' => $token,
            'fileName' => $request->file('file')->getClientOriginalName(),
            'valid' => $result['valid'],
            'rowErrors' => $result['errors'],
            'missingColumns' => $result['missing_columns'],
        ]);
    }

    /**
     * Import the previously checked file. It is checked again, because data may have
     * changed since the preview; any error cancels the whole import.
     */
    public function store(Request $request, string $token, ProductImporter $importer): RedirectResponse
    {
        $this->authorize('import', Product::class);

        $path = $request->session()->pull("catalog_import.{$token}");
        abort_if(! is_string($path) || ! Storage::disk('local')->exists($path), 404);

        try {
            $result = $importer->check($importer->read(Storage::disk('local')->path($path)));

            if ($result['errors'] !== [] || $result['missing_columns'] !== []) {
                return redirect()->route('catalog.products.import')->with('error', 'The file has errors now (did someone add products meanwhile?). Upload it again to see them.');
            }

            $count = $importer->commit($result['valid'], $request->user());
        } finally {
            Storage::disk('local')->delete($path);
        }

        return redirect()->route('catalog.products.index')->with('success', "{$count} products imported.");
    }

    public function export(Request $request): BinaryFileResponse
    {
        $this->authorize('viewAny', Product::class);

        $query = $this->applyListQuery(
            Product::query(),
            $request,
            searchable: ['short_code', 'name', 'name_si', 'aliases', 'sku'],
            sortable: ['short_code', 'name', 'created_at', 'updated_at'],
            filters: ProductController::listFilters(),
            defaultSort: 'short_code',
            defaultDirection: 'asc',
        );

        return (new ProductsExport($query, $request->user()->can('viewCost', Product::class)))
            ->download('products-'.now()->format('Y-m-d').'.xlsx');
    }
}
