<?php

namespace App\Http\Controllers\Admin;

use App\Domain\System\Import\OpeningBalanceImporter;
use App\Domain\System\Import\OpeningBalanceTemplate;
use App\Domain\System\Services\GoLiveChecklist;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Go-live: the readiness checklist and the import of customers and suppliers with the
 * balances from the old books (template → upload → preview → import). Super Admin only.
 */
class GoLiveController extends Controller
{
    private const DIRECTORY = 'imports';

    public function index(GoLiveChecklist $checklist): View
    {
        return view('admin.go-live.index', ['items' => $checklist->items()]);
    }

    public function template(string $type): BinaryFileResponse
    {
        return (new OpeningBalanceTemplate($type))->download("{$type}-opening-balances-template.xlsx");
    }

    /**
     * Upload and check the file. Nothing is saved yet.
     */
    public function preview(Request $request, string $type, OpeningBalanceImporter $importer): View|RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240']]);

        $token = (string) Str::uuid();
        $path = $request->file('file')->storeAs(self::DIRECTORY, "{$token}.".$request->file('file')->getClientOriginalExtension(), 'local');
        $request->session()->put("go_live_import.{$token}", $path);

        $result = $importer->check($type, $importer->read(Storage::disk('local')->path($path)));

        if ($result['valid'] === [] && $result['errors'] === [] && $result['missing_columns'] === []) {
            return back()->with('error', 'The file has no rows.');
        }

        return view('admin.go-live.preview', [
            'type' => $type,
            'token' => $token,
            'fileName' => $request->file('file')->getClientOriginalName(),
            'valid' => $result['valid'],
            'rowErrors' => $result['errors'],
            'missingColumns' => $result['missing_columns'],
        ]);
    }

    /**
     * Import the checked file (checked again; any error cancels the whole import).
     */
    public function store(Request $request, string $type, string $token, OpeningBalanceImporter $importer): RedirectResponse
    {
        $path = $request->session()->pull("go_live_import.{$token}");
        abort_if(! is_string($path) || ! Storage::disk('local')->exists($path), 404);

        try {
            $result = $importer->check($type, $importer->read(Storage::disk('local')->path($path)));

            if ($result['errors'] !== [] || $result['missing_columns'] !== [] || $result['valid'] === []) {
                return redirect()->route('admin.go-live.index')->with('error', 'The file has errors now. Upload it again to see them.');
            }

            $count = $importer->commit($type, $result['valid'], $request->user());
        } finally {
            Storage::disk('local')->delete($path);
        }

        return redirect()->route($type === 'customers' ? 'customers.index' : 'purchasing.suppliers.index')
            ->with('success', "{$count} {$type} imported with their opening balances.");
    }
}
