<x-ui.tabs class="mb-4 print:hidden" :active="$active" :tabs="[
    'profit-loss' => ['label' => 'Profit & loss', 'href' => route('finance.reports.profit-loss')],
    'balance-sheet' => ['label' => 'Balance sheet', 'href' => route('finance.reports.balance-sheet')],
    'trial-balance' => ['label' => 'Trial balance', 'href' => route('finance.reports.trial-balance')],
    'cash-book' => ['label' => 'Cash book', 'href' => route('finance.reports.cash-book')],
]" />
