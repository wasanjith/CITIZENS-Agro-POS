<?php

namespace App\Domain\Finance\Notifications;

use App\Domain\System\Notifications\AppNotification;

/**
 * Morning reminder: received cheques that can be deposited and issued cheques the bank
 * will be asked to pay soon.
 */
class ChequesDueAlert extends AppNotification
{
    public function __construct(
        public readonly int $toDeposit,
        public readonly string $toDepositAmount,
        public readonly int $issuedDue,
        public readonly string $issuedDueAmount,
        public readonly int $days,
    ) {}

    public function title(): string
    {
        return 'Cheques due';
    }

    public function message(): string
    {
        $parts = [];

        if ($this->toDeposit > 0) {
            $parts[] = "{$this->toDeposit} received ".str('cheque')->plural($this->toDeposit).' (Rs. '.number_format((float) $this->toDepositAmount, 2).') can be deposited';
        }

        if ($this->issuedDue > 0) {
            $parts[] = "{$this->issuedDue} issued ".str('cheque')->plural($this->issuedDue).' (Rs. '.number_format((float) $this->issuedDueAmount, 2).") fall due within {$this->days} days: keep the money in the bank";
        }

        return implode('; ', $parts).'.';
    }

    public function url(): string
    {
        return route('finance.cheques.calendar');
    }
}
