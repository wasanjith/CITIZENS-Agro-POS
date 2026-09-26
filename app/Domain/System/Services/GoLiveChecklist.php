<?php

namespace App\Domain\System\Services;

use App\Domain\Catalog\Models\OpeningStockEntry;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customers\Models\Customer;
use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Models\BankAccount;
use App\Domain\Finance\Services\ChartOfAccounts;
use App\Domain\HR\Models\Employee;
use App\Domain\Identity\Enums\TerminalType;
use App\Domain\Identity\Models\Printer;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Sales\Support\Money;
use App\Models\User;

/**
 * The go-live checklist (plan section 17): what the system can check by itself, and the
 * steps only people can confirm (training, UPS test, parallel run).
 */
class GoLiveChecklist
{
    public function __construct(private readonly ChartOfAccounts $accounts) {}

    /**
     * @return list<array{label: string, done: bool|null, detail: string, route: string|null}>
     */
    public function items(): array
    {
        $products = Product::query()->where('is_active', true)->count();
        $pendingOpening = OpeningStockEntry::query()->whereNull('posted_at')->count();
        $customers = Customer::query()->count();
        $suppliers = Supplier::query()->count();
        $users = User::query()->active()->get(['id', 'pin_hash']);
        $withPin = $users->whereNotNull('pin_hash')->count();
        $employees = Employee::query()->where('is_active', true)->count();
        $linked = Employee::query()->where('is_active', true)->whereNotNull('user_id')->count();
        $mainRegistered = Terminal::query()->active()->where('type', TerminalType::MainCashier)->whereNotNull('device_token_hash')->count();
        $countersRegistered = Terminal::query()->active()->where('type', TerminalType::Counter)->whereNotNull('device_token_hash')->count();
        $printers = Printer::query()->where('is_active', true)->get(['id', 'last_test_at', 'has_cash_drawer']);
        $owner = User::role('super_admin')->whereNotNull('two_factor_confirmed_at')->exists();

        return [
            ['label' => 'Products imported and active', 'done' => $products > 0, 'detail' => number_format($products).' active products', 'route' => 'catalog.products.index'],
            ['label' => 'Opening stock posted', 'done' => $products > 0 && $pendingOpening === 0, 'detail' => $pendingOpening > 0 ? "{$pendingOpening} opening stock entries not yet posted" : 'Nothing waiting', 'route' => 'inventory.stock.index'],
            ['label' => 'Customer credit balances imported', 'done' => $customers > 0, 'detail' => number_format($customers).' customers · receivable Rs. '.Money::format($this->accounts->get(SystemAccount::AccountsReceivable)->balance()), 'route' => 'customers.index'],
            ['label' => 'Supplier balances imported', 'done' => $suppliers > 0, 'detail' => number_format($suppliers).' suppliers · payable Rs. '.Money::format($this->accounts->get(SystemAccount::AccountsPayable)->balance()), 'route' => 'purchasing.suppliers.index'],
            ['label' => 'Bank accounts with opening balances', 'done' => BankAccount::query()->exists(), 'detail' => BankAccount::query()->count().' bank accounts', 'route' => 'finance.bank-accounts.index'],
            ['label' => 'Every user has a PIN for the terminals', 'done' => $users->isNotEmpty() && $withPin === $users->count(), 'detail' => "{$withPin} of {$users->count()} active users", 'route' => 'admin.users.index'],
            ['label' => 'Employees linked to their logins', 'done' => $employees > 0 && $linked === $employees, 'detail' => "{$linked} of {$employees} active employees", 'route' => 'hr.employees.index'],
            ['label' => 'Main cashier and 3 counters registered', 'done' => $mainRegistered >= 1 && $countersRegistered >= 3, 'detail' => "Main cashier: {$mainRegistered} · counters: {$countersRegistered}", 'route' => 'admin.terminals.index'],
            ['label' => 'Test print on every printer', 'done' => $printers->count() >= 4 && $printers->whereNull('last_test_at')->isEmpty(), 'detail' => $printers->whereNotNull('last_test_at')->count().' of '.$printers->count().' printers test-printed', 'route' => 'admin.printers.index'],
            ['label' => 'Cash drawer on the main printer', 'done' => $printers->where('has_cash_drawer', true)->isNotEmpty(), 'detail' => 'Drawer opens on settlement (check by hand)', 'route' => 'admin.printers.index'],
            ['label' => 'Owner account has two-factor sign-in', 'done' => $owner, 'detail' => 'Needed before the dashboard is opened from a phone', 'route' => 'account'],
            ['label' => 'Live Billing checked on the owner\'s phone with all 3 counters billing', 'done' => null, 'detail' => 'Confirm by hand', 'route' => 'admin.live-billing'],
            ['label' => 'Handover rehearsed (owner → manager → owner)', 'done' => null, 'detail' => 'Confirm by hand', 'route' => 'admin.delegations.index'],
            ['label' => 'Backups restored on a test machine; UPS shutdown tested', 'done' => null, 'detail' => 'Confirm by hand', 'route' => null],
            ['label' => 'Staff trained; 1–2 week parallel run with daily reconciliation', 'done' => null, 'detail' => 'Daily summary (Z report) vs the old system each day', 'route' => 'reports.index'],
        ];
    }
}
