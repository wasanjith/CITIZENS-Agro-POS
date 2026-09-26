<?php

namespace App\Http\Controllers\Finance;

use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Services\ChartOfAccounts;
use App\Domain\Finance\Services\FinancialReports;
use App\Http\Controllers\Concerns\ChoosesPeriod;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Trial balance, profit & loss, balance sheet and cash book (reports.finance).
 */
class FinancialReportController extends Controller
{
    use ChoosesPeriod;

    public function __construct(private readonly FinancialReports $reports) {}

    public function trialBalance(Request $request): View
    {
        $this->authorize('reports.finance');

        $asOf = $this->dateInput($request, 'as_of') ?? today();

        return view('finance.reports.trial-balance', ['asOf' => $asOf, ...$this->reports->trialBalance($asOf)]);
    }

    public function profitAndLoss(Request $request): View
    {
        $this->authorize('reports.finance');

        [$from, $to] = $this->period($request);

        return view('finance.reports.profit-loss', ['from' => $from, 'to' => $to, 'report' => $this->reports->profitAndLoss($from, $to)]);
    }

    public function balanceSheet(Request $request): View
    {
        $this->authorize('reports.finance');

        $asOf = $this->dateInput($request, 'as_of') ?? today();

        return view('finance.reports.balance-sheet', ['asOf' => $asOf, 'report' => $this->reports->balanceSheet($asOf)]);
    }

    /**
     * Cash drawer or safe ledger with a running balance.
     */
    public function cashBook(Request $request, ChartOfAccounts $chart): View
    {
        $this->authorize('reports.finance');

        [$from, $to] = $this->period($request);
        $which = $request->query('account') === 'drawer' ? SystemAccount::CashDrawer : SystemAccount::Safe;
        $account = $chart->get($which);

        return view('finance.reports.cash-book', [
            'account' => $account,
            'which' => $which,
            'from' => $from,
            'to' => $to,
            ...$this->reports->ledger($account, $from, $to),
            'accounts' => [SystemAccount::Safe->value => SystemAccount::Safe->label(), 'drawer' => SystemAccount::CashDrawer->label()],
        ]);
    }
}
