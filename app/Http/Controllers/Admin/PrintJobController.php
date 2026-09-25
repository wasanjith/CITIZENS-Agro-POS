<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\Printer;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Sales\Enums\PrintDocumentType;
use App\Domain\Sales\Models\PrintJob;
use App\Domain\Sales\Models\Sale;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /admin/print-jobs: every print and reprint (filters: terminal, user, type, copies only).
 */
class PrintJobController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Printer::class);

        $filters = $request->validate([
            'terminal_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string'],
            'copies' => ['nullable', 'boolean'],
            'date' => ['nullable', 'date'],
        ]);

        $jobs = PrintJob::query()
            ->with(['terminal', 'printer', 'user'])
            ->when($filters['terminal_id'] ?? null, fn ($query, $id) => $query->where('terminal_id', $id))
            ->when($filters['user_id'] ?? null, fn ($query, $id) => $query->where('user_id', $id))
            ->when(PrintDocumentType::tryFrom($filters['type'] ?? ''), fn ($query, $type) => $query->where('document_type', $type))
            ->when($filters['copies'] ?? false, fn ($query) => $query->where('is_copy', true))
            ->when($filters['date'] ?? null, fn ($query, $date) => $query->whereDate('created_at', $date))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        $invoiceNumbers = Sale::query()
            ->whereIn('id', $jobs->getCollection()->where('document_type', PrintDocumentType::Invoice)->pluck('document_id'))
            ->pluck('invoice_no', 'id');

        return view('admin.print-jobs.index', [
            'jobs' => $jobs,
            'invoiceNumbers' => $invoiceNumbers,
            'terminals' => Terminal::query()->orderBy('name')->get(),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'types' => PrintDocumentType::options(),
        ]);
    }
}
