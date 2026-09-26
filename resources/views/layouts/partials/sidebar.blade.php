@php
    $sections = [
        'Overview' => [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'active' => 'dashboard', 'can' => null],
        ],
        'POS' => [
            ['label' => 'Billing screen', 'route' => 'pos.counter', 'active' => 'pos.counter', 'can' => 'pos.sell'],
            ['label' => 'Cashier', 'route' => 'pos.cashier', 'active' => 'pos.cashier', 'can' => 'pos.settle'],
            ['label' => 'Live Billing', 'route' => 'admin.live-billing', 'active' => 'admin.live-billing', 'can' => 'pos.live_view'],
            ['label' => 'Invoices', 'route' => 'sales.index', 'active' => 'sales.*', 'can' => ['pos.settle', 'pos.live_view', 'reports.sales']],
            ['label' => 'Cashier authority', 'route' => 'admin.delegations.index', 'active' => 'admin.delegations.*', 'can' => 'drawer.handover'],
            ['label' => 'Drawer sessions', 'route' => 'admin.drawer-sessions.index', 'active' => 'admin.drawer-sessions.*', 'can' => ['drawer.handover', 'drawer.manage', 'reports.sales']],
        ],
        'Customers' => [
            ['label' => 'Customers', 'route' => 'customers.index', 'active' => ['customers.index', 'customers.create', 'customers.show', 'customers.edit'], 'can' => 'customers.view'],
            ['label' => 'Credit ageing', 'route' => 'customers.ageing', 'active' => 'customers.ageing', 'can' => 'customers.view'],
            ['label' => 'Customer payments', 'route' => 'customers.payments.index', 'active' => 'customers.payments.*', 'can' => 'customers.view'],
            ['label' => 'Returns', 'route' => 'sales.returns.index', 'active' => 'sales.returns.*', 'can' => ['pos.refund', 'pos.settle', 'reports.sales']],
            ['label' => 'Quotations', 'route' => 'quotations.index', 'active' => 'quotations.*', 'can' => ['pos.sell', 'customers.view']],
        ],
        'Catalog' => [
            ['label' => 'Products', 'route' => 'catalog.products.index', 'active' => 'catalog.products.*', 'can' => 'catalog.view'],
            ['label' => 'Categories', 'route' => 'catalog.categories.index', 'active' => 'catalog.categories.*', 'can' => 'catalog.manage'],
            ['label' => 'Brands', 'route' => 'catalog.brands.index', 'active' => 'catalog.brands.*', 'can' => 'catalog.manage'],
            ['label' => 'Units', 'route' => 'catalog.units.index', 'active' => 'catalog.units.*', 'can' => 'catalog.manage'],
            ['label' => 'Search synonyms', 'route' => 'catalog.synonyms.index', 'active' => 'catalog.synonyms.*', 'can' => 'catalog.synonyms.manage'],
            ['label' => 'Taxes', 'route' => 'catalog.taxes.index', 'active' => 'catalog.taxes.*', 'can' => 'admin.settings.manage'],
        ],
        'Inventory' => [
            ['label' => 'Stock on hand', 'route' => 'inventory.stock.index', 'active' => 'inventory.stock.*', 'can' => 'inventory.view'],
            ['label' => 'Expiring stock', 'route' => 'inventory.batches.expiry', 'active' => 'inventory.batches.*', 'can' => 'inventory.view'],
            ['label' => 'Stock movements', 'route' => 'inventory.movements.index', 'active' => 'inventory.movements.*', 'can' => 'inventory.view'],
            ['label' => 'Adjustments', 'route' => 'inventory.adjustments.index', 'active' => 'inventory.adjustments.*', 'can' => ['inventory.adjust', 'inventory.adjust.approve']],
            ['label' => 'Stocktakes', 'route' => 'inventory.stocktakes.index', 'active' => 'inventory.stocktakes.*', 'can' => 'inventory.stocktake'],
        ],
        'Purchasing' => [
            ['label' => 'Purchase orders', 'route' => 'purchasing.purchase-orders.index', 'active' => 'purchasing.purchase-orders.*', 'can' => ['purchasing.po.create', 'purchasing.po.approve']],
            ['label' => 'Goods received', 'route' => 'purchasing.goods-receipts.index', 'active' => 'purchasing.goods-receipts.*', 'can' => 'purchasing.grn.create'],
            ['label' => 'Supplier returns', 'route' => 'purchasing.supplier-returns.index', 'active' => 'purchasing.supplier-returns.*', 'can' => 'purchasing.grn.create'],
            ['label' => 'Suppliers', 'route' => 'purchasing.suppliers.index', 'active' => 'purchasing.suppliers.*', 'can' => 'purchasing.suppliers.manage'],
            ['label' => 'Supplier payments', 'route' => 'purchasing.supplier-payments.index', 'active' => 'purchasing.supplier-payments.*', 'can' => ['purchasing.suppliers.pay', 'purchasing.suppliers.manage']],
        ],
        'Finance' => [
            ['label' => 'Banking', 'route' => 'finance.bank-accounts.index', 'active' => ['finance.bank-accounts.*', 'finance.money.*'], 'can' => 'finance.banks.manage'],
            ['label' => 'Cheques', 'route' => 'finance.cheques.index', 'active' => ['finance.cheques.index', 'finance.cheques.show'], 'can' => 'finance.cheques.manage'],
            ['label' => 'Cheque calendar', 'route' => 'finance.cheques.calendar', 'active' => 'finance.cheques.calendar', 'can' => 'finance.cheques.manage'],
            ['label' => 'Expenses', 'route' => 'finance.expenses.index', 'active' => ['finance.expenses.*', 'finance.expense-categories.*'], 'can' => 'finance.expenses.manage'],
            ['label' => 'Journal', 'route' => 'finance.journal.index', 'active' => 'finance.journal.*', 'can' => 'finance.journal.view'],
            ['label' => 'Chart of accounts', 'route' => 'finance.accounts.index', 'active' => 'finance.accounts.*', 'can' => 'finance.journal.view'],
            ['label' => 'Financial reports', 'route' => 'finance.reports.profit-loss', 'active' => 'finance.reports.*', 'can' => 'reports.finance'],
        ],
        'Administration' => [
            ['label' => 'Users', 'route' => 'admin.users.index', 'active' => 'admin.users.*', 'can' => 'admin.users.manage'],
            ['label' => 'Terminals', 'route' => 'admin.terminals.index', 'active' => 'admin.terminals.*', 'can' => 'admin.terminals.manage'],
            ['label' => 'Printers', 'route' => 'admin.printers.index', 'active' => 'admin.printers.*', 'can' => 'admin.terminals.manage'],
            ['label' => 'Print log', 'route' => 'admin.print-jobs.index', 'active' => 'admin.print-jobs.*', 'can' => 'admin.terminals.manage'],
            ['label' => 'Printing test', 'route' => 'admin.printing-test', 'active' => 'admin.printing-test*', 'can' => 'admin.terminals.manage'],
            ['label' => 'Settings', 'route' => 'admin.settings.edit', 'params' => ['group' => 'shop'], 'active' => 'admin.settings.*', 'can' => 'admin.settings.manage'],
            ['label' => 'Audit log', 'route' => 'admin.audit.index', 'active' => 'admin.audit.*', 'can' => 'admin.audit.view'],
        ],
    ];
@endphp

<nav class="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto px-3 py-4 sidebar-scroll" aria-label="Main">
    @foreach ($sections as $heading => $items)
        @php
            $visible = array_filter($items, fn ($item) => $item['can'] === null || auth()->user()->canAny((array) $item['can']));
        @endphp
        @if ($visible)
            <div>
                <p class="px-3 text-xs font-semibold uppercase tracking-wide text-brand-200/80">{{ $heading }}</p>
                <ul class="mt-2 space-y-1">
                    @foreach ($visible as $item)
                        @php $isActive = request()->routeIs(...(array) $item['active']); @endphp
                        <li>
                            <a
                                href="{{ route($item['route'], $item['params'] ?? []) }}"
                                @class([
                                    'block rounded-md px-3 py-2 text-sm font-medium',
                                    'bg-brand-800 text-white' => $isActive,
                                    'text-brand-100 hover:bg-brand-800/60 hover:text-white' => ! $isActive,
                                ])
                                @if ($isActive) aria-current="page" @endif
                            >{{ $item['label'] }}</a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endforeach
</nav>
