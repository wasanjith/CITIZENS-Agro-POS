<?php

namespace App\Domain\Customers\Notifications;

use App\Domain\System\Notifications\AppNotification;

/**
 * Morning summary: customers with credit invoices past their due date.
 */
class OverdueCreditAlert extends AppNotification
{
    public function __construct(
        public readonly int $customerCount,
        public readonly string $amount,
    ) {}

    public function title(): string
    {
        return 'Overdue credit';
    }

    public function message(): string
    {
        return "{$this->customerCount} ".str('customer')->plural($this->customerCount).' owe Rs. '.number_format((float) $this->amount, 2).' past the due date.';
    }

    public function url(): string
    {
        return route('customers.ageing');
    }
}
