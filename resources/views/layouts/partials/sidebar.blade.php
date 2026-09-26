@php
    // Heroicons (outline, 24px) path data for each main topic.
    $icons = [
        'home' => ['m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25'],
        'cart' => ['M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z'],
        'users' => ['M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z'],
        'tag' => ['M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z', 'M6 6h.008v.008H6V6Z'],
        'cube' => ['m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9'],
        'truck' => ['M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12'],
        'banknotes' => ['M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z'],
        'briefcase' => ['M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0 1 12 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 0 1-.673-.38m0 0A2.18 2.18 0 0 1 3 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 0 1 3.413-.387m7.5 0V5.25A2.25 2.25 0 0 0 13.5 3h-3a2.25 2.25 0 0 0-2.25 2.25v.894m7.5 0a48.667 48.667 0 0 0-7.5 0M12 12.75h.008v.008H12v-.008Z'],
        'cog' => ['M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z', 'M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z'],
    ];

    $dashboardActive = request()->routeIs('dashboard');

    $sections = [
        'POS' => ['icon' => 'cart', 'items' => [
            ['label' => 'Billing screen', 'route' => 'pos.counter', 'active' => 'pos.counter', 'can' => 'pos.sell'],
            ['label' => 'Cashier', 'route' => 'pos.cashier', 'active' => 'pos.cashier', 'can' => 'pos.settle'],
            ['label' => 'Live Billing', 'route' => 'admin.live-billing', 'active' => 'admin.live-billing', 'can' => 'pos.live_view'],
            ['label' => 'Invoices', 'route' => 'sales.index', 'active' => 'sales.*', 'can' => ['pos.settle', 'pos.live_view', 'reports.sales']],
            ['label' => 'Cashier authority', 'route' => 'admin.delegations.index', 'active' => 'admin.delegations.*', 'can' => 'drawer.handover'],
            ['label' => 'Drawer sessions', 'route' => 'admin.drawer-sessions.index', 'active' => 'admin.drawer-sessions.*', 'can' => ['drawer.handover', 'drawer.manage', 'reports.sales']],
        ]],
        'Customers' => ['icon' => 'users', 'items' => [
            ['label' => 'Customers', 'route' => 'customers.index', 'active' => ['customers.index', 'customers.create', 'customers.show', 'customers.edit'], 'can' => 'customers.view'],
            ['label' => 'Credit ageing', 'route' => 'customers.ageing', 'active' => 'customers.ageing', 'can' => 'customers.view'],
            ['label' => 'Customer payments', 'route' => 'customers.payments.index', 'active' => 'customers.payments.*', 'can' => 'customers.view'],
            ['label' => 'Returns', 'route' => 'sales.returns.index', 'active' => 'sales.returns.*', 'can' => ['pos.refund', 'pos.settle', 'reports.sales']],
            ['label' => 'Quotations', 'route' => 'quotations.index', 'active' => 'quotations.*', 'can' => ['pos.sell', 'customers.view']],
        ]],
        'Catalog' => ['icon' => 'tag', 'items' => [
            ['label' => 'Products', 'route' => 'catalog.products.index', 'active' => 'catalog.products.*', 'can' => 'catalog.view'],
            ['label' => 'Categories', 'route' => 'catalog.categories.index', 'active' => 'catalog.categories.*', 'can' => 'catalog.manage'],
            ['label' => 'Brands', 'route' => 'catalog.brands.index', 'active' => 'catalog.brands.*', 'can' => 'catalog.manage'],
            ['label' => 'Units', 'route' => 'catalog.units.index', 'active' => 'catalog.units.*', 'can' => 'catalog.manage'],
            ['label' => 'Search synonyms', 'route' => 'catalog.synonyms.index', 'active' => 'catalog.synonyms.*', 'can' => 'catalog.synonyms.manage'],
            ['label' => 'Taxes', 'route' => 'catalog.taxes.index', 'active' => 'catalog.taxes.*', 'can' => 'admin.settings.manage'],
        ]],
        'Inventory' => ['icon' => 'cube', 'items' => [
            ['label' => 'Stock on hand', 'route' => 'inventory.stock.index', 'active' => 'inventory.stock.*', 'can' => 'inventory.view'],
            ['label' => 'Expiring stock', 'route' => 'inventory.batches.expiry', 'active' => 'inventory.batches.*', 'can' => 'inventory.view'],
            ['label' => 'Stock movements', 'route' => 'inventory.movements.index', 'active' => 'inventory.movements.*', 'can' => 'inventory.view'],
            ['label' => 'Adjustments', 'route' => 'inventory.adjustments.index', 'active' => 'inventory.adjustments.*', 'can' => ['inventory.adjust', 'inventory.adjust.approve']],
            ['label' => 'Stocktakes', 'route' => 'inventory.stocktakes.index', 'active' => 'inventory.stocktakes.*', 'can' => 'inventory.stocktake'],
        ]],
        'Purchasing' => ['icon' => 'truck', 'items' => [
            ['label' => 'Purchase orders', 'route' => 'purchasing.purchase-orders.index', 'active' => 'purchasing.purchase-orders.*', 'can' => ['purchasing.po.create', 'purchasing.po.approve']],
            ['label' => 'Goods received', 'route' => 'purchasing.goods-receipts.index', 'active' => 'purchasing.goods-receipts.*', 'can' => 'purchasing.grn.create'],
            ['label' => 'Supplier returns', 'route' => 'purchasing.supplier-returns.index', 'active' => 'purchasing.supplier-returns.*', 'can' => 'purchasing.grn.create'],
            ['label' => 'Suppliers', 'route' => 'purchasing.suppliers.index', 'active' => 'purchasing.suppliers.*', 'can' => 'purchasing.suppliers.manage'],
            ['label' => 'Supplier payments', 'route' => 'purchasing.supplier-payments.index', 'active' => 'purchasing.supplier-payments.*', 'can' => ['purchasing.suppliers.pay', 'purchasing.suppliers.manage']],
        ]],
        'Finance' => ['icon' => 'banknotes', 'items' => [
            ['label' => 'Banking', 'route' => 'finance.bank-accounts.index', 'active' => ['finance.bank-accounts.*', 'finance.money.*'], 'can' => 'finance.banks.manage'],
            ['label' => 'Cheques', 'route' => 'finance.cheques.index', 'active' => ['finance.cheques.index', 'finance.cheques.show'], 'can' => 'finance.cheques.manage'],
            ['label' => 'Cheque calendar', 'route' => 'finance.cheques.calendar', 'active' => 'finance.cheques.calendar', 'can' => 'finance.cheques.manage'],
            ['label' => 'Expenses', 'route' => 'finance.expenses.index', 'active' => ['finance.expenses.*', 'finance.expense-categories.*'], 'can' => 'finance.expenses.manage'],
            ['label' => 'Journal', 'route' => 'finance.journal.index', 'active' => 'finance.journal.*', 'can' => 'finance.journal.view'],
            ['label' => 'Chart of accounts', 'route' => 'finance.accounts.index', 'active' => 'finance.accounts.*', 'can' => 'finance.journal.view'],
            ['label' => 'Financial reports', 'route' => 'finance.reports.profit-loss', 'active' => 'finance.reports.*', 'can' => 'reports.finance'],
        ]],
        'HR & Payroll' => ['icon' => 'briefcase', 'items' => [
            ['label' => 'My attendance', 'route' => 'hr.attendance.mine', 'active' => 'hr.attendance.mine', 'can' => 'hr.attendance.self'],
            ['label' => 'Attendance', 'route' => 'hr.attendance.index', 'active' => ['hr.attendance.index', 'hr.attendance.month'], 'can' => 'hr.attendance.view'],
            ['label' => 'Leave', 'route' => 'hr.leave.index', 'active' => 'hr.leave.*', 'can' => ['hr.attendance.view', 'hr.employees.manage']],
            ['label' => 'Employees', 'route' => 'hr.employees.index', 'active' => 'hr.employees.*', 'can' => 'hr.employees.manage'],
            ['label' => 'Payroll', 'route' => 'hr.payroll.index', 'active' => ['hr.payroll.*', 'hr.payslips.*'], 'can' => 'hr.payroll.manage'],
            ['label' => 'Salary advances', 'route' => 'hr.advances.index', 'active' => 'hr.advances.*', 'can' => 'hr.payroll.manage'],
            ['label' => 'Allowances & deductions', 'route' => 'hr.components.index', 'active' => 'hr.components.*', 'can' => 'hr.payroll.manage'],
            ['label' => 'Shifts, holidays & leave', 'route' => 'hr.setup.index', 'active' => 'hr.setup.*', 'can' => 'hr.employees.manage'],
            ['label' => 'HR reports', 'route' => 'hr.reports.attendance', 'active' => 'hr.reports.*', 'can' => 'reports.hr'],
        ]],
        'Administration' => ['icon' => 'cog', 'items' => [
            ['label' => 'Users', 'route' => 'admin.users.index', 'active' => 'admin.users.*', 'can' => 'admin.users.manage'],
            ['label' => 'Terminals', 'route' => 'admin.terminals.index', 'active' => 'admin.terminals.*', 'can' => 'admin.terminals.manage'],
            ['label' => 'Printers', 'route' => 'admin.printers.index', 'active' => 'admin.printers.*', 'can' => 'admin.terminals.manage'],
            ['label' => 'Print log', 'route' => 'admin.print-jobs.index', 'active' => 'admin.print-jobs.*', 'can' => 'admin.terminals.manage'],
            ['label' => 'Printing test', 'route' => 'admin.printing-test', 'active' => 'admin.printing-test*', 'can' => 'admin.terminals.manage'],
            ['label' => 'Settings', 'route' => 'admin.settings.edit', 'params' => ['group' => 'shop'], 'active' => 'admin.settings.*', 'can' => 'admin.settings.manage'],
            ['label' => 'Audit log', 'route' => 'admin.audit.index', 'active' => 'admin.audit.*', 'can' => 'admin.audit.view'],
        ]],
    ];
@endphp

<nav class="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto px-3 py-4 sidebar-scroll" aria-label="Main">
    <a
        href="{{ route('dashboard') }}"
        @class([
            'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-semibold',
            'bg-brand-800 text-white' => $dashboardActive,
            'text-brand-100 hover:bg-brand-800/60 hover:text-white' => ! $dashboardActive,
        ])
        @if ($dashboardActive) aria-current="page" @endif
    >
        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            @foreach ($icons['home'] as $d)<path stroke-linecap="round" stroke-linejoin="round" d="{{ $d }}" />@endforeach
        </svg>
        Dashboard
    </a>

    @foreach ($sections as $heading => $section)
        @php
            $visible = array_filter($section['items'], fn ($item) => $item['can'] === null || auth()->user()->canAny((array) $item['can']));
            $sectionActive = collect($visible)->contains(fn ($item) => request()->routeIs(...(array) $item['active']));
        @endphp
        @if ($visible)
            <div x-data="{ open: @js($sectionActive) }">
                <button
                    type="button"
                    @click="open = ! open"
                    :aria-expanded="open.toString()"
                    @class([
                        'flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm font-semibold hover:bg-brand-800/60 hover:text-white',
                        'text-white' => $sectionActive,
                        'text-brand-100' => ! $sectionActive,
                    ])
                >
                    <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        @foreach ($icons[$section['icon']] as $d)<path stroke-linecap="round" stroke-linejoin="round" d="{{ $d }}" />@endforeach
                    </svg>
                    <span class="flex-1">{{ $heading }}</span>
                    <svg
                        @class(['size-4 shrink-0 text-brand-200/80 transition-transform duration-200', 'rotate-180' => $sectionActive])
                        :class="{ 'rotate-180': open }"
                        fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </button>

                {{-- Grid-rows trick animates the height without a collapse plugin. --}}
                <div
                    @class(['grid transition-[grid-template-rows] duration-200 ease-out', 'grid-rows-[1fr]' => $sectionActive, 'grid-rows-[0fr]' => ! $sectionActive])
                    :class="{ 'grid-rows-[1fr]': open, 'grid-rows-[0fr]': ! open }"
                >
                    <div class="overflow-hidden">
                        {{-- Track line runs under the topic icon's centre (px-3 + half of size-5 = 22px). --}}
                        <ul class="relative my-1 space-y-0.5 before:absolute before:inset-y-1 before:left-[22px] before:w-px before:bg-brand-700">
                            @foreach ($visible as $item)
                                @php $isActive = request()->routeIs(...(array) $item['active']); @endphp
                                <li>
                                    <a
                                        href="{{ route($item['route'], $item['params'] ?? []) }}"
                                        :tabindex="open ? 0 : -1"
                                        @class([
                                            'group relative flex items-center rounded-md py-1.5 pl-11 pr-3 text-sm',
                                            'font-semibold text-white' => $isActive,
                                            'font-medium text-brand-100/90 hover:text-white' => ! $isActive,
                                        ])
                                        @if ($isActive) aria-current="page" @endif
                                    >
                                        <span
                                            @class([
                                                'absolute left-[22px] top-1/2 -translate-x-1/2 -translate-y-1/2 rounded-full transition',
                                                'size-2 bg-brand-300 shadow-[0_0_4px_1px_var(--color-brand-300),0_0_10px_3px_rgb(124_214_166/0.55)]' => $isActive,
                                                'size-1.5 bg-brand-600 group-hover:bg-brand-300' => ! $isActive,
                                            ])
                                            aria-hidden="true"
                                        ></span>
                                        {{ $item['label'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif
    @endforeach
</nav>
