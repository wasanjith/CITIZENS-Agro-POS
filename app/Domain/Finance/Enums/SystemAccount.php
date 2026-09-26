<?php

namespace App\Domain\Finance\Enums;

/**
 * Accounts the application posts to automatically (IMPLEMENTATION_PLAN section 14).
 * The value is accounts.system_key. Bank accounts get their own ledger accounts (15xx)
 * and expense categories theirs (6xxx) when they are created.
 */
enum SystemAccount: string
{
    case CashDrawer = 'cash_drawer';
    case Safe = 'safe';
    case CardClearing = 'card_clearing';
    case ChequesInHand = 'cheques_in_hand';
    case AccountsReceivable = 'accounts_receivable';
    case DishonouredCheques = 'dishonoured_cheques';
    case Inventory = 'inventory';
    case StaffAdvances = 'staff_advances';

    case AccountsPayable = 'accounts_payable';
    case ChequesIssued = 'cheques_issued';
    case TaxPayable = 'tax_payable';
    case SalariesPayable = 'salaries_payable';
    case EpfPayable = 'epf_payable';
    case EtfPayable = 'etf_payable';

    case OwnerCapital = 'owner_capital';
    case OpeningBalanceEquity = 'opening_balance_equity';
    case OwnerDrawings = 'owner_drawings';

    case SalesRevenue = 'sales_revenue';
    case SalesReturns = 'sales_returns';
    case InventoryGain = 'inventory_gain';
    case CashOver = 'cash_over';
    case InterestIncome = 'interest_income';

    case CostOfGoodsSold = 'cogs';
    case InventoryLoss = 'inventory_loss';
    case CashShort = 'cash_short';
    case BankCharges = 'bank_charges';
    case Salaries = 'salaries';
    case EpfEtfExpense = 'epf_etf_expense';

    public function code(): string
    {
        return match ($this) {
            self::CashDrawer => '1010',
            self::Safe => '1020',
            self::CardClearing => '1030',
            self::ChequesInHand => '1040',
            self::AccountsReceivable => '1100',
            self::DishonouredCheques => '1110',
            self::Inventory => '1200',
            self::StaffAdvances => '1300',
            self::AccountsPayable => '2010',
            self::ChequesIssued => '2020',
            self::TaxPayable => '2030',
            self::SalariesPayable => '2040',
            self::EpfPayable => '2050',
            self::EtfPayable => '2060',
            self::OwnerCapital => '3010',
            self::OpeningBalanceEquity => '3020',
            self::OwnerDrawings => '3030',
            self::SalesRevenue => '4010',
            self::SalesReturns => '4020',
            self::InventoryGain => '4030',
            self::CashOver => '4040',
            self::InterestIncome => '4050',
            self::CostOfGoodsSold => '5010',
            self::InventoryLoss => '5020',
            self::CashShort => '5030',
            self::BankCharges => '5040',
            self::Salaries => '5050',
            self::EpfEtfExpense => '5060',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CashDrawer => 'Cash drawer',
            self::Safe => 'Cash at home (day\'s takings)',
            self::CardClearing => 'Card & transfer clearing',
            self::ChequesInHand => 'Cheques in hand',
            self::AccountsReceivable => 'Accounts receivable (customers)',
            self::DishonouredCheques => 'Dishonoured cheques (walk-in)',
            self::Inventory => 'Inventory',
            self::StaffAdvances => 'Staff advances',
            self::AccountsPayable => 'Accounts payable (suppliers)',
            self::ChequesIssued => 'Cheques issued, not cleared',
            self::TaxPayable => 'Tax payable',
            self::SalariesPayable => 'Salaries payable',
            self::EpfPayable => 'EPF payable',
            self::EtfPayable => 'ETF payable',
            self::OwnerCapital => 'Owner capital',
            self::OpeningBalanceEquity => 'Opening balances',
            self::OwnerDrawings => 'Owner drawings',
            self::SalesRevenue => 'Sales',
            self::SalesReturns => 'Sales returns',
            self::InventoryGain => 'Stock gains',
            self::CashOver => 'Cash over',
            self::InterestIncome => 'Bank interest',
            self::CostOfGoodsSold => 'Cost of goods sold',
            self::InventoryLoss => 'Stock losses and damage',
            self::CashShort => 'Cash short',
            self::BankCharges => 'Bank charges',
            self::Salaries => 'Salaries',
            self::EpfEtfExpense => 'EPF / ETF (employer)',
        };
    }

    public function type(): AccountType
    {
        return match ($this->code()[0]) {
            '1' => AccountType::Asset,
            '2' => AccountType::Liability,
            '3' => AccountType::Equity,
            '4' => AccountType::Income,
            default => AccountType::Expense,
        };
    }
}
