<?php

namespace App\Providers;

use App\Domain\CashDrawer\Models\CashMovement;
use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\Catalog\Models\OpeningStockEntry;
use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Models\CustomerPayment;
use App\Domain\Finance\Models\BankAccount;
use App\Domain\Finance\Models\BankTransaction;
use App\Domain\Finance\Models\Cheque;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\HR\Models\Employee;
use App\Domain\HR\Models\PayrollRun;
use App\Domain\HR\Models\Payslip;
use App\Domain\HR\Models\SalaryAdvance;
use App\Domain\Identity\Services\CashierAuthority;
use App\Domain\Identity\Services\DelegationService;
use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\Stocktake;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Models\SupplierPayment;
use App\Domain\Purchasing\Models\SupplierReturn;
use App\Domain\Reports\ReportRegistry;
use App\Domain\Reports\Services\ReportLookups;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleReturn;
use App\Domain\System\Services\Settings;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentTerminal::class);
        $this->app->scoped(DelegationService::class);
        $this->app->singleton(Settings::class);
        $this->app->scoped(ReportRegistry::class);
        $this->app->scoped(ReportLookups::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Short, stable names for the documents stock movements and ledger rows point at.
        Relation::morphMap([
            'opening_stock' => OpeningStockEntry::class,
            'stock_adjustment' => StockAdjustment::class,
            'stocktake' => Stocktake::class,
            'purchase_order' => PurchaseOrder::class,
            'goods_receipt' => GoodsReceipt::class,
            'supplier_return' => SupplierReturn::class,
            'sale' => Sale::class,
            'sale_return' => SaleReturn::class,
            'customer_payment' => CustomerPayment::class,
            'customer' => Customer::class,
            'supplier' => Supplier::class,
            'supplier_payment' => SupplierPayment::class,
            'drawer_session' => DrawerSession::class,
            'cash_movement' => CashMovement::class,
            'bank_account' => BankAccount::class,
            'bank_transaction' => BankTransaction::class,
            'cheque' => Cheque::class,
            'expense' => Expense::class,
            'journal_entry' => JournalEntry::class,
            'employee' => Employee::class,
            'salary_advance' => SalaryAdvance::class,
            'payroll_run' => PayrollRun::class,
            'payslip' => Payslip::class,
        ]);

        $this->registerGates();
        $this->shareLayoutData();
    }

    /**
     * Super Admin passes every check. Anyone else may additionally hold a
     * permission through an active Cashier Handover delegation. Returning null
     * falls through to the normal role permissions (spatie/laravel-permission).
     */
    private function registerGates(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            if ($user->isSuperAdmin()) {
                return true;
            }

            if (app(DelegationService::class)->grants($user, $ability)) {
                return true;
            }

            return null;
        });
    }

    private function shareLayoutData(): void
    {
        View::composer('layouts.app', function (\Illuminate\View\View $view): void {
            $view->with([
                'currentTerminal' => app(CurrentTerminal::class)->get(),
                'cashierHolder' => app(CashierAuthority::class)->holder(),
            ]);
        });
    }
}
