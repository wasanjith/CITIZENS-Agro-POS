<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DelegationController;
use App\Http\Controllers\Admin\DrawerSessionController;
use App\Http\Controllers\Admin\LiveBillingController;
use App\Http\Controllers\Admin\PrinterController;
use App\Http\Controllers\Admin\PrintingTestController;
use App\Http\Controllers\Admin\PrintJobController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TerminalController;
use App\Http\Controllers\Admin\TerminalDeviceController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Api\BatchLookupController;
use App\Http\Controllers\Api\LiveBillingSnapshotController;
use App\Http\Controllers\Api\ProductSearchController;
use App\Http\Controllers\Auth\PinLoginController;
use App\Http\Controllers\Catalog\BrandController;
use App\Http\Controllers\Catalog\CategoryController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Catalog\ProductImportController;
use App\Http\Controllers\Catalog\SearchSynonymController;
use App\Http\Controllers\Catalog\TaxController;
use App\Http\Controllers\Catalog\UnitController;
use App\Http\Controllers\Customers\CustomerController;
use App\Http\Controllers\Customers\CustomerPaymentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Finance\AccountController as FinanceAccountController;
use App\Http\Controllers\Finance\BankAccountController;
use App\Http\Controllers\Finance\ChequeController;
use App\Http\Controllers\Finance\ExpenseCategoryController;
use App\Http\Controllers\Finance\ExpenseController;
use App\Http\Controllers\Finance\FinancialReportController;
use App\Http\Controllers\Finance\JournalController;
use App\Http\Controllers\Finance\MoneyMoveController;
use App\Http\Controllers\Inventory\StockAdjustmentController;
use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Inventory\StockMovementController;
use App\Http\Controllers\Inventory\StocktakeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Pos\ApprovalController;
use App\Http\Controllers\Pos\CartController;
use App\Http\Controllers\Pos\CashierController;
use App\Http\Controllers\Pos\CounterController;
use App\Http\Controllers\Pos\CustomerController as PosCustomerController;
use App\Http\Controllers\Pos\DrawerController;
use App\Http\Controllers\Pos\HandoverController;
use App\Http\Controllers\Pos\HoldController;
use App\Http\Controllers\Pos\InvoiceController;
use App\Http\Controllers\Pos\PrintController;
use App\Http\Controllers\Pos\QuotationController as PosQuotationController;
use App\Http\Controllers\Purchasing\GoodsReceiptController;
use App\Http\Controllers\Purchasing\PurchaseOrderController;
use App\Http\Controllers\Purchasing\SupplierController;
use App\Http\Controllers\Purchasing\SupplierPaymentController;
use App\Http\Controllers\Purchasing\SupplierReturnController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\Sales\QuotationController;
use App\Http\Controllers\Sales\SaleReturnController;
use App\Http\Controllers\UnregisteredTerminalController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::get('/terminal/unregistered', UnregisteredTerminalController::class)->name('terminal.unregistered');

// Purchase order PDF shared with a supplier over WhatsApp: signed link, no sign-in.
Route::get('/po/{purchase_order}.pdf', [PurchaseOrderController::class, 'sharedPdf'])->middleware('signed')->name('purchasing.purchase-orders.shared-pdf');

Route::middleware(['guest', 'terminal'])->group(function () {
    Route::get('/pin-login', [PinLoginController::class, 'create'])->name('pin-login');
    Route::post('/pin-login', [PinLoginController::class, 'store'])->name('pin-login.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/account', [AccountController::class, 'show'])->name('account');

    Route::get('/api/pos/search', ProductSearchController::class)->middleware('throttle:120,1')->name('api.pos.search');

    /*
    | Phase 3: POS. The counter screen works on every registered terminal; settlement,
    | the drawer and handovers only on the main cashier terminal. "cashier" = the open
    | drawer is held by you and you hold cashier authority.
    */
    Route::middleware(['terminal', 'can:pos.sell'])->group(function () {
        Route::get('/pos', CounterController::class)->name('pos.counter');

        Route::prefix('api/pos')->name('api.pos.')->group(function () {
            Route::get('cart', [CartController::class, 'show'])->name('cart.show');
            Route::post('cart-sync', [CartController::class, 'sync'])->middleware('throttle:600,1')->name('cart.sync');
            Route::get('inbox', [CartController::class, 'inbox'])->name('inbox');
            Route::post('void-restores/{sale}/dismiss', [CartController::class, 'dismissVoidRestore'])->name('void-restores.dismiss');
            Route::get('quick-items', [CartController::class, 'quickItems'])->name('quick-items');

            Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
            Route::get('invoices/today', [InvoiceController::class, 'today'])->name('invoices.today');
            Route::post('sales/{sale}/reprint', [InvoiceController::class, 'reprint'])->name('sales.reprint');

            Route::get('holds', [HoldController::class, 'index'])->name('holds.index');
            Route::post('holds', [HoldController::class, 'store'])->name('holds.store');
            Route::post('holds/{sale}/recall', [HoldController::class, 'recall'])->name('holds.recall');

            Route::post('approvals', [ApprovalController::class, 'store'])->name('approvals.store');

            // Phase 4: customers (F4) and quotations (F7) on the counter screen.
            Route::get('customers', [PosCustomerController::class, 'index'])->name('customers.index');
            Route::post('customers', [PosCustomerController::class, 'store'])->name('customers.store');
            Route::get('customers/{customer}', [PosCustomerController::class, 'show'])->name('customers.show');
            Route::get('quotations', [PosQuotationController::class, 'index'])->name('quotations.index');
            Route::post('quotations', [PosQuotationController::class, 'store'])->name('quotations.store');
            Route::get('quotations/{quotation}/cart', [PosQuotationController::class, 'cart'])->name('quotations.cart');
            Route::post('quotations/{quotation}/reprint', [PosQuotationController::class, 'reprint'])->name('quotations.reprint');
        });
    });

    Route::middleware('terminal')->group(function () {
        Route::get('/pos/sales/{sale}/invoice', [PrintController::class, 'invoice'])->name('pos.sales.invoice');
        Route::get('/pos/printers/test-page', [PrintController::class, 'testPage'])->name('pos.printers.test-page');
        Route::post('/api/pos/print-jobs/{printJob}/printed', [PrintController::class, 'printed'])->name('api.pos.print-jobs.printed');
        Route::get('/pos/customer-payments/{customerPayment}/receipt', [PrintController::class, 'paymentReceipt'])->name('pos.customer-payments.receipt');
        Route::get('/pos/returns/{saleReturn}/receipt', [PrintController::class, 'returnReceipt'])->name('pos.returns.receipt');
        Route::get('/pos/quotations/{quotation}/print', [PrintController::class, 'quotation'])->name('pos.quotations.print');
        Route::get('/pos/sales/{sale}/credit-bill', [PrintController::class, 'creditBill'])->name('pos.sales.credit-bill');
    });
    Route::get('/pos/sales/{sale}/invoice.pdf', [PrintController::class, 'invoicePdf'])->name('pos.sales.invoice-pdf');

    Route::middleware('terminal:main_cashier')->prefix('pos')->name('pos.')->group(function () {
        Route::get('drawer', [DrawerController::class, 'show'])->name('drawer.show');
        Route::get('drawer/open', [DrawerController::class, 'create'])->name('drawer.open');
        Route::post('drawer/open', [DrawerController::class, 'store'])->name('drawer.store');
        Route::get('drawer/close', [DrawerController::class, 'closeForm'])->name('drawer.close');
        Route::post('drawer/close', [DrawerController::class, 'close'])->name('drawer.close.store');

        Route::get('handover/return', [HandoverController::class, 'returnForm'])->name('handover.return');
        Route::post('handover/return', [HandoverController::class, 'returnStore'])->name('handover.return.store');
        Route::get('authority-ended', [HandoverController::class, 'authorityEnded'])->name('authority-ended');

        Route::middleware('cashier')->group(function () {
            Route::get('cashier', [CashierController::class, 'index'])->name('cashier');
            Route::post('drawer/movements', [DrawerController::class, 'movement'])->middleware('can:drawer.manage')->name('drawer.movements.store');
            Route::get('handover', [HandoverController::class, 'create'])->middleware('can:drawer.handover')->name('handover.create');
            Route::post('handover', [HandoverController::class, 'store'])->middleware('can:drawer.handover')->name('handover.store');
            Route::post('sales/{sale}/void', [CashierController::class, 'void'])->middleware('can:pos.void')->name('sales.void');
            Route::post('sales/{sale}/credit-bill/reprint', [PrintController::class, 'reprintCreditBill'])->middleware('can:pos.settle')->name('sales.credit-bill.reprint');

            // Phase 4: money in and out that goes through the drawer.
            Route::middleware('can:customers.credit.manage')->group(function () {
                Route::get('customer-payment', [CustomerPaymentController::class, 'create'])->name('customer-payments.create');
                Route::post('customers/{customer}/payments', [CustomerPaymentController::class, 'store'])->name('customer-payments.store');
                Route::post('customer-payments/{customerPayment}/reprint', [CustomerPaymentController::class, 'reprint'])->name('customer-payments.reprint');
            });
            Route::middleware('can:pos.refund')->group(function () {
                Route::get('returns/create', [SaleReturnController::class, 'create'])->name('returns.create');
                Route::post('sales/{sale}/returns', [SaleReturnController::class, 'store'])->name('returns.store');
                Route::post('returns/{saleReturn}/reprint', [SaleReturnController::class, 'reprint'])->name('returns.reprint');
            });
        });
    });

    Route::middleware(['terminal:main_cashier', 'cashier'])->prefix('api/pos')->name('api.pos.')->group(function () {
        Route::post('sales/{sale}/settle', [CashierController::class, 'settle'])->name('sales.settle');
        Route::get('sales/find', [CashierController::class, 'find'])->name('sales.find');
        Route::post('approvals/{approvalRequest}/approve', [ApprovalController::class, 'approve'])->middleware('can:pos.approve_requests')->name('approvals.approve');
        Route::post('approvals/{approvalRequest}/reject', [ApprovalController::class, 'reject'])->middleware('can:pos.approve_requests')->name('approvals.reject');
    });

    Route::get('/pos/drawer/sessions/{drawerSession}/report', [DrawerController::class, 'report'])->name('pos.drawer.report');
    Route::get('/pos/drawer/sessions/{drawerSession}/report.pdf', [DrawerController::class, 'reportPdf'])->name('pos.drawer.report-pdf');

    Route::get('/api/live-billing/snapshot', LiveBillingSnapshotController::class)->middleware('can:pos.live_view')->name('api.live-billing.snapshot');

    Route::get('/sales', [SaleController::class, 'index'])->name('sales.index');
    Route::get('/sales/returns', [SaleReturnController::class, 'index'])->name('sales.returns.index');
    Route::get('/sales/returns/{saleReturn}', [SaleReturnController::class, 'show'])->name('sales.returns.show');
    Route::get('/sales/{sale}', [SaleController::class, 'show'])->name('sales.show');

    Route::get('/quotations', [QuotationController::class, 'index'])->name('quotations.index');
    Route::get('/quotations/{quotation}', [QuotationController::class, 'show'])->name('quotations.show');
    Route::get('/quotations/{quotation}/pdf', [QuotationController::class, 'pdf'])->name('quotations.pdf');
    Route::post('/quotations/{quotation}/cancel', [QuotationController::class, 'cancel'])->name('quotations.cancel');

    Route::get('/customers-ageing', [CustomerController::class, 'ageing'])->name('customers.ageing');
    Route::get('/customer-payments', [CustomerPaymentController::class, 'index'])->name('customers.payments.index');
    Route::get('/customer-payments/{customerPayment}', [CustomerPaymentController::class, 'show'])->name('customers.payments.show');
    Route::get('/customers/{customer}/statement.pdf', [CustomerController::class, 'statement'])->name('customers.statement');
    Route::get('/api/customers', [CustomerController::class, 'lookup'])->name('api.customers');
    Route::resource('customers', CustomerController::class);

    Route::prefix('catalog')->name('catalog.')->group(function () {
        Route::get('products/export', [ProductImportController::class, 'export'])->name('products.export');
        Route::get('products/import', [ProductImportController::class, 'create'])->name('products.import');
        Route::get('products/import/template', [ProductImportController::class, 'template'])->name('products.import.template');
        Route::post('products/import', [ProductImportController::class, 'preview'])->name('products.import.preview');
        Route::post('products/import/{token}', [ProductImportController::class, 'store'])->whereUuid('token')->name('products.import.store');
        Route::get('products/next-code', [ProductController::class, 'nextCode'])->name('products.next-code');
        Route::resource('products', ProductController::class);

        Route::resource('categories', CategoryController::class)->except(['show']);
        Route::resource('brands', BrandController::class)->except(['show']);
        Route::resource('units', UnitController::class)->except(['show', 'destroy']);
        Route::resource('taxes', TaxController::class)->except(['show', 'destroy']);
        Route::resource('synonyms', SearchSynonymController::class)->except(['show']);
    });

    Route::get('/api/inventory/batches', BatchLookupController::class)->name('api.inventory.batches');
    Route::get('/api/purchasing/suppliers', [SupplierController::class, 'lookup'])->name('api.purchasing.suppliers');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/{notification}', [NotificationController::class, 'open'])->whereUuid('notification')->name('notifications.open');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');

    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('stock', [StockController::class, 'index'])->name('stock.index');
        Route::post('stock/opening', [StockController::class, 'postOpening'])->name('stock.post-opening');
        Route::get('expiry', [StockController::class, 'expiry'])->name('batches.expiry');
        Route::get('movements', [StockMovementController::class, 'index'])->name('movements.index');

        Route::resource('adjustments', StockAdjustmentController::class)->only(['index', 'create', 'store', 'show']);
        Route::post('adjustments/{adjustment}/approve', [StockAdjustmentController::class, 'approve'])->name('adjustments.approve');
        Route::post('adjustments/{adjustment}/reject', [StockAdjustmentController::class, 'reject'])->name('adjustments.reject');

        Route::resource('stocktakes', StocktakeController::class)->only(['index', 'create', 'store', 'show']);
        Route::get('stocktakes/{stocktake}/count', [StocktakeController::class, 'count'])->name('stocktakes.count');
        Route::put('stocktakes/{stocktake}/count', [StocktakeController::class, 'saveCounts'])->name('stocktakes.counts');
        Route::get('stocktakes/{stocktake}/sheet', [StocktakeController::class, 'sheet'])->name('stocktakes.sheet');
        Route::post('stocktakes/{stocktake}/finish', [StocktakeController::class, 'finish'])->name('stocktakes.finish');
        Route::post('stocktakes/{stocktake}/reopen', [StocktakeController::class, 'reopen'])->name('stocktakes.reopen');
        Route::post('stocktakes/{stocktake}/post', [StocktakeController::class, 'post'])->name('stocktakes.post');
        Route::post('stocktakes/{stocktake}/cancel', [StocktakeController::class, 'cancel'])->name('stocktakes.cancel');
    });

    Route::prefix('purchasing')->name('purchasing.')->group(function () {
        Route::resource('suppliers', SupplierController::class);

        Route::get('purchase-orders/reorder-suggestions', [PurchaseOrderController::class, 'suggestions'])->name('purchase-orders.suggestions');
        Route::resource('purchase-orders', PurchaseOrderController::class)->except(['destroy']);
        Route::post('purchase-orders/{purchase_order}/submit', [PurchaseOrderController::class, 'submit'])->name('purchase-orders.submit');
        Route::post('purchase-orders/{purchase_order}/approve', [PurchaseOrderController::class, 'approve'])->name('purchase-orders.approve');
        Route::post('purchase-orders/{purchase_order}/reject', [PurchaseOrderController::class, 'reject'])->name('purchase-orders.reject');
        Route::post('purchase-orders/{purchase_order}/send', [PurchaseOrderController::class, 'markSent'])->name('purchase-orders.send');
        Route::post('purchase-orders/{purchase_order}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
        Route::post('purchase-orders/{purchase_order}/close', [PurchaseOrderController::class, 'close'])->name('purchase-orders.close');
        Route::get('purchase-orders/{purchase_order}/pdf', [PurchaseOrderController::class, 'pdf'])->name('purchase-orders.pdf');

        Route::resource('goods-receipts', GoodsReceiptController::class)->except(['destroy']);
        Route::post('goods-receipts/{goods_receipt}/post', [GoodsReceiptController::class, 'post'])->name('goods-receipts.post');
        Route::post('goods-receipts/{goods_receipt}/cancel', [GoodsReceiptController::class, 'cancel'])->name('goods-receipts.cancel');

        Route::resource('supplier-returns', SupplierReturnController::class)->only(['index', 'create', 'store', 'show']);

        Route::get('supplier-payments', [SupplierPaymentController::class, 'index'])->name('supplier-payments.index');
        Route::get('supplier-payments/create', [SupplierPaymentController::class, 'create'])->name('supplier-payments.create');
        Route::post('suppliers/{supplier}/payments', [SupplierPaymentController::class, 'store'])->name('supplier-payments.store');
        Route::get('supplier-payments/{supplierPayment}', [SupplierPaymentController::class, 'show'])->name('supplier-payments.show');
    });

    /*
    | Phase 5: Finance & Banking. Banks, cheques, the journal and the financial reports
    | belong to the Super Admin (never delegated); the Manager records petty-cash expenses.
    */
    Route::prefix('finance')->name('finance.')->group(function () {
        Route::get('bank-accounts', [BankAccountController::class, 'index'])->name('bank-accounts.index');
        Route::get('bank-accounts/create', [BankAccountController::class, 'create'])->name('bank-accounts.create');
        Route::post('bank-accounts', [BankAccountController::class, 'store'])->name('bank-accounts.store');
        Route::get('bank-accounts/{bankAccount}', [BankAccountController::class, 'show'])->name('bank-accounts.show');
        Route::get('bank-accounts/{bankAccount}/edit', [BankAccountController::class, 'edit'])->name('bank-accounts.edit');
        Route::put('bank-accounts/{bankAccount}', [BankAccountController::class, 'update'])->name('bank-accounts.update');
        Route::post('bank-accounts/{bankAccount}/charges', [BankAccountController::class, 'charge'])->name('bank-accounts.charge');
        Route::get('bank-accounts/{bankAccount}/reconcile', [BankAccountController::class, 'reconcileForm'])->name('bank-accounts.reconcile');
        Route::post('bank-accounts/{bankAccount}/reconcile', [BankAccountController::class, 'reconcile'])->name('bank-accounts.reconcile.store');

        Route::get('move-money', [MoneyMoveController::class, 'create'])->name('money.create');
        Route::post('move-money', [MoneyMoveController::class, 'store'])->name('money.store');

        Route::get('cheques', [ChequeController::class, 'index'])->name('cheques.index');
        Route::get('cheques/calendar', [ChequeController::class, 'calendar'])->name('cheques.calendar');
        Route::get('cheques/{cheque}', [ChequeController::class, 'show'])->name('cheques.show');
        Route::put('cheques/{cheque}', [ChequeController::class, 'update'])->name('cheques.update');
        Route::post('cheques/{cheque}/deposit', [ChequeController::class, 'deposit'])->name('cheques.deposit');
        Route::post('cheques/{cheque}/clear', [ChequeController::class, 'clear'])->name('cheques.clear');
        Route::post('cheques/{cheque}/bounce', [ChequeController::class, 'bounce'])->name('cheques.bounce');
        Route::post('cheques/{cheque}/cancel', [ChequeController::class, 'cancel'])->name('cheques.cancel');

        Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::get('expenses/create', [ExpenseController::class, 'create'])->name('expenses.create');
        Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
        Route::get('expenses/{expense}', [ExpenseController::class, 'show'])->name('expenses.show');
        Route::get('expenses/{expense}/receipt', [ExpenseController::class, 'receipt'])->name('expenses.receipt');
        Route::post('expenses/{expense}/cancel', [ExpenseController::class, 'cancel'])->name('expenses.cancel');

        Route::get('expense-categories', [ExpenseCategoryController::class, 'index'])->name('expense-categories.index');
        Route::post('expense-categories', [ExpenseCategoryController::class, 'store'])->name('expense-categories.store');
        Route::put('expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'update'])->name('expense-categories.update');

        Route::resource('accounts', FinanceAccountController::class)->except(['destroy']);
        Route::get('journal', [JournalController::class, 'index'])->name('journal.index');
        Route::get('journal/{journalEntry}', [JournalController::class, 'show'])->name('journal.show');

        Route::get('reports/trial-balance', [FinancialReportController::class, 'trialBalance'])->name('reports.trial-balance');
        Route::get('reports/profit-and-loss', [FinancialReportController::class, 'profitAndLoss'])->name('reports.profit-loss');
        Route::get('reports/balance-sheet', [FinancialReportController::class, 'balanceSheet'])->name('reports.balance-sheet');
        Route::get('reports/cash-book', [FinancialReportController::class, 'cashBook'])->name('reports.cash-book');
    });

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', UserController::class)->except(['show', 'destroy']);

        Route::resource('terminals', TerminalController::class)->except(['show', 'destroy']);
        Route::get('terminals/{terminal}/register', [TerminalDeviceController::class, 'show'])->name('terminals.register');
        Route::post('terminals/{terminal}/register', [TerminalDeviceController::class, 'store'])->name('terminals.register.store');
        Route::delete('terminals/{terminal}/register', [TerminalDeviceController::class, 'destroy'])->name('terminals.register.destroy');

        Route::resource('printers', PrinterController::class)->except(['show', 'destroy']);
        Route::post('printers/{printer}/test', [PrinterController::class, 'test'])->name('printers.test');
        Route::post('printers/{printer}/assign', [PrinterController::class, 'assign'])->name('printers.assign');
        Route::get('print-jobs', [PrintJobController::class, 'index'])->name('print-jobs.index');

        Route::get('live-billing', LiveBillingController::class)->middleware('can:pos.live_view')->name('live-billing');
        Route::get('delegations', [DelegationController::class, 'index'])->name('delegations.index');
        Route::post('delegations/{delegation}/revoke', [DelegationController::class, 'revoke'])->name('delegations.revoke');
        Route::get('drawer-sessions', [DrawerSessionController::class, 'index'])->name('drawer-sessions.index');

        Route::get('printing-test', [PrintingTestController::class, 'index'])->name('printing-test');
        Route::get('printing-test/thermal', [PrintingTestController::class, 'thermal'])->name('printing-test.thermal');
        Route::get('printing-test/pdf', [PrintingTestController::class, 'pdf'])->name('printing-test.pdf');

        Route::middleware('can:admin.settings.manage')->group(function () {
            Route::get('settings/{group}', [SettingsController::class, 'edit'])->name('settings.edit');
            Route::put('settings/{group}', [SettingsController::class, 'update'])->name('settings.update');
        });

        Route::get('audit', [AuditLogController::class, 'index'])->middleware('can:admin.audit.view')->name('audit.index');
    });
});
