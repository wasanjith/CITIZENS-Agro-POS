<?php

namespace App\Http\Controllers\Finance;

use App\Domain\Finance\Actions\ClearChequeAction;
use App\Domain\Finance\Actions\DepositChequeAction;
use App\Domain\Finance\Actions\ReturnChequeAction;
use App\Domain\Finance\Enums\ChequeDirection;
use App\Domain\Finance\Enums\ChequeStatus;
use App\Domain\Finance\Models\BankAccount;
use App\Domain\Finance\Models\Cheque;
use App\Domain\Finance\Services\FinancePosting;
use App\Http\Controllers\Concerns\HasListQuery;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The cheque register: received and issued cheques, the post-dated cheque calendar and
 * the deposit → clear / bounce steps.
 */
class ChequeController extends Controller
{
    use HasListQuery;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Cheque::class);

        $direction = ChequeDirection::tryFrom((string) $request->query('direction')) ?? ChequeDirection::Received;

        $cheques = $this->applyListQuery(
            Cheque::query()->with(['party', 'bankAccount'])->where('direction', $direction),
            $request,
            searchable: ['number', 'bank_name', 'party_name'],
            sortable: ['cheque_date', 'amount', 'created_at'],
            filters: [
                'status' => function (Builder $query, string $status): void {
                    $query->whereIn('status', $status === 'open' ? [ChequeStatus::Pending, ChequeStatus::Deposited] : [$status]);
                },
            ],
            defaultSort: 'cheque_date',
            defaultDirection: 'asc',
        )->paginate(50)->withQueryString();

        return view('finance.cheques.index', [
            'cheques' => $cheques,
            'direction' => $direction,
            'statuses' => ['open' => 'Not yet cleared', ...ChequeStatus::options()],
        ]);
    }

    /**
     * Month grid of open cheques by cheque date (post-dated cheque calendar).
     */
    public function calendar(Request $request): View
    {
        $this->authorize('viewAny', Cheque::class);

        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? Carbon::createFromFormat('Y-m-d', $request->query('month').'-01')->startOfDay() : today()->startOfMonth();
        $start = $month->copy()->startOfWeek(Carbon::MONDAY);
        $end = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $cheques = Cheque::query()->with('party')->open()
            ->whereDate('cheque_date', '>=', $start)
            ->whereDate('cheque_date', '<=', $end)
            ->orderBy('cheque_date')
            ->get()
            ->groupBy(fn (Cheque $cheque) => $cheque->cheque_date->toDateString());

        return view('finance.cheques.calendar', [
            'month' => $month,
            'start' => $start,
            'end' => $end,
            'cheques' => $cheques,
            'overdue' => Cheque::query()->with('party')->open()->whereDate('cheque_date', '<', $start)->orderBy('cheque_date')->get(),
        ]);
    }

    public function show(Cheque $cheque, FinancePosting $posting): View
    {
        $this->authorize('view', $cheque);

        return view('finance.cheques.show', [
            'cheque' => $cheque->load(['party', 'source', 'bankAccount', 'createdBy']),
            'banks' => BankAccount::options(),
            'entries' => $posting->entriesFor($cheque),
        ]);
    }

    /**
     * Correct the details of a cheque that has not cleared (bank, branch, date, number).
     */
    public function update(Request $request, Cheque $cheque): RedirectResponse
    {
        $this->authorize('update', $cheque);

        if ($cheque->status !== ChequeStatus::Pending) {
            return back()->withErrors(['cheque' => 'Only a pending cheque can be changed.']);
        }

        $cheque->update($request->validate([
            'number' => ['required', 'string', 'max:30'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'branch' => ['nullable', 'string', 'max:100'],
            'cheque_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]));

        return back()->with('success', 'Cheque details saved.');
    }

    public function deposit(Request $request, Cheque $cheque, DepositChequeAction $deposit): RedirectResponse
    {
        $this->authorize('update', $cheque);

        $validated = $request->validate([
            'bank_account_id' => ['required', Rule::exists('bank_accounts', 'id')->where('is_active', true)],
            'date' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $deposit->handle($cheque, BankAccount::query()->findOrFail($validated['bank_account_id']), Carbon::parse($validated['date']), $request->user());

        return back()->with('success', "Cheque {$cheque->number} deposited.");
    }

    public function clear(Request $request, Cheque $cheque, ClearChequeAction $clear): RedirectResponse
    {
        $this->authorize('update', $cheque);

        $validated = $request->validate(['date' => ['required', 'date', 'before_or_equal:today']]);
        $clear->handle($cheque, Carbon::parse($validated['date']), $request->user());

        return back()->with('success', "Cheque {$cheque->number} cleared.");
    }

    public function bounce(Request $request, Cheque $cheque, ReturnChequeAction $return): RedirectResponse
    {
        return $this->returnCheque($request, $cheque, $return, cancel: false);
    }

    public function cancel(Request $request, Cheque $cheque, ReturnChequeAction $return): RedirectResponse
    {
        return $this->returnCheque($request, $cheque, $return, cancel: true);
    }

    private function returnCheque(Request $request, Cheque $cheque, ReturnChequeAction $return, bool $cancel): RedirectResponse
    {
        $this->authorize('update', $cheque);

        $validated = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:200'],
        ]);

        $return->handle($cheque, Carbon::parse($validated['date']), $request->user(), $validated['reason'], $cancel);

        $message = $cheque->direction === ChequeDirection::Received
            ? "Cheque {$cheque->number} marked as bounced. {$cheque->partyLabel()} owes the money again."
            : "Cheque {$cheque->number} ".($cancel ? 'cancelled' : 'marked as bounced').'. The supplier is owed the money again.';

        return back()->with('success', $message);
    }
}
