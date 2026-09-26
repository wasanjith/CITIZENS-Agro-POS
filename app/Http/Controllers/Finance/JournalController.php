<?php

namespace App\Http\Controllers\Finance;

use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\JournalEntry;
use App\Http\Controllers\Concerns\ChoosesPeriod;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The journal: every entry the system posted, newest first.
 */
class JournalController extends Controller
{
    use ChoosesPeriod;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Account::class);

        [$from, $to] = $this->period($request);
        $search = trim((string) $request->query('search', ''));

        $entries = JournalEntry::query()
            ->with(['lines.account', 'createdBy'])
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('number', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%")))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString();

        return view('finance.journal.index', ['entries' => $entries, 'from' => $from, 'to' => $to]);
    }

    public function show(JournalEntry $journalEntry): View
    {
        $this->authorize('view', Account::class);

        return view('finance.journal.show', ['entry' => $journalEntry->load(['lines.account', 'createdBy', 'reverses', 'reversal', 'source'])]);
    }
}
