<?php

namespace App\Domain\Customers\Policies;

use App\Domain\Customers\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    /**
     * Customer list, balances and statements. Sales Staff may look, not change.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('customers.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->can('customers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('customers.manage');
    }

    /**
     * Name, phone and village from the POS screen (F4). The credit limit stays 0, so
     * credit for a new customer still needs the owner.
     */
    public function quickAdd(User $user): bool
    {
        return $user->can('customers.manage') || $user->can('pos.sell');
    }

    /**
     * Profile, price list, credit limit and credit days.
     */
    public function update(User $user, Customer $customer): bool
    {
        return $user->can('customers.manage');
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->can('customers.manage');
    }

    /**
     * Take a payment towards the account (at the main cashier).
     */
    public function receivePayment(User $user, Customer $customer): bool
    {
        return $user->can('customers.credit.manage');
    }
}
