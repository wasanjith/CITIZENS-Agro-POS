<?php

namespace App\Domain\Customers\Actions;

use App\Domain\Customers\Enums\CustomerLedgerType;
use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Services\CustomerLedger;
use App\Domain\Sales\Support\Money;
use App\Domain\System\Services\DocumentNumber;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create or update a customer. A new customer gets the next code (C-00001) and, when
 * given, an opening balance: what they already owed from the old books (ledger debit).
 */
class SaveCustomerAction
{
    public function __construct(
        private readonly DocumentNumber $numbers,
        private readonly CustomerLedger $ledger,
    ) {}

    /**
     * @param  array<string, mixed>  $data  customer fields, plus opening_balance for a new customer
     */
    public function handle(array $data, User $user, ?Customer $customer = null): Customer
    {
        $openingBalance = Money::of($data['opening_balance'] ?? '0');
        unset($data['opening_balance']);

        $data['phone'] = $this->normalisePhone($data['phone'] ?? null);

        return DB::transaction(function () use ($data, $user, $customer, $openingBalance): Customer {
            if ($customer !== null) {
                $customer->update($data);

                return $customer;
            }

            $customer = Customer::create([
                ...$data,
                'code' => $this->numbers->next('CUST'),
                'created_by' => $user->id,
            ]);

            if ($openingBalance->isPositive()) {
                $this->ledger->debit($customer->id, CustomerLedgerType::Opening, 'Opening balance', $openingBalance, today(), $user->id, today(), 'Balance brought forward');
            }

            // Columns filled by database defaults must exist (models are strict).
            return $customer->refresh();
        });
    }

    /**
     * Keep digits only (077 123 4567 → 0771234567) so the unique index and search work.
     */
    public function normalisePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '94') && strlen($digits) === 11) {
            $digits = '0'.substr($digits, 2);
        }

        return $digits;
    }
}
