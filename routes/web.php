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
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Inventory\StockAdjustmentController;
use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Inventory\StockMovementController;
use App\Http\Controllers\Inventory\StocktakeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Pos\ApprovalController;
use App\Http\Controllers\Pos\CartController;
use App\Http\Controllers\Pos\CashierController;
use App\Http\Controllers\Pos\CounterController;
use App\Http\Controllers\Pos\DrawerController;
use App\Http\Controllers\Pos\HandoverController;
use App\Http\Controllers\Pos\HoldController;
use App\Http\Controllers\Pos\InvoiceController;
use App\Http\Controllers\Pos\PrintController;
use App\Http\Controllers\Purchasing\GoodsReceiptController;
use App\Http\Controllers\Purchasing\PurchaseOrderController;
use App\Http\Controllers\Purchasing\SupplierController;
use App\Http\Controllers\Purchasing\SupplierReturnController;
use App\Http\Controllers\SaleController;
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
        });
    });

    Route::middleware('terminal')->group(function () {
        Route::get('/pos/sales/{sale}/invoice', [PrintController::class, 'invoice'])->name('pos.sales.invoice');
        Route::get('/pos/printers/test-page', [PrintController::class, 'testPage'])->name('pos.printers.test-page');
        Route::post('/api/pos/print-jobs/{printJob}/printed', [PrintController::class, 'printed'])->name('api.pos.print-jobs.printed');
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
    Route::get('/sales/{sale}', [SaleController::class, 'show'])->name('sales.show');

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
