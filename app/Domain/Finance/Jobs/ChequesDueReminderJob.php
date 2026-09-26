<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Enums\ChequeDirection;
use App\Domain\Finance\Enums\ChequeStatus;
use App\Domain\Finance\Models\Cheque;
use App\Domain\Finance\Notifications\ChequesDueAlert;
use App\Domain\Sales\Support\Money;
use App\Domain\System\Services\Settings;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/**
 * Every morning: tells the owner (bell notification) about received cheques dated today
 * or earlier that are still in hand, and issued cheques that fall due within the next
 * few days (Settings → Finance).
 */
class ChequesDueReminderJob implements ShouldQueue
{
    use Queueable;

    public function handle(Settings $settings): void
    {
        if (! (bool) $settings->get('finance.cheque_alert', true)) {
            return;
        }

        $days = (int) $settings->get('finance.cheque_alert_days', 3);

        $toDeposit = Cheque::query()
            ->where('direction', ChequeDirection::Received)
            ->where('status', ChequeStatus::Pending)
            ->whereDate('cheque_date', '<=', today())
            ->get(['id', 'amount']);

        $issued = Cheque::query()
            ->where('direction', ChequeDirection::Issued)
            ->where('status', ChequeStatus::Pending)
            ->whereDate('cheque_date', '<=', today()->addDays($days))
            ->get(['id', 'amount']);

        if ($toDeposit->isEmpty() && $issued->isEmpty()) {
            return;
        }

        $sum = fn ($cheques): string => (string) $cheques->reduce(fn (BigDecimal $total, Cheque $cheque) => $total->plus($cheque->amount), Money::zero());

        Notification::send(
            User::permission('finance.cheques.manage')->active()->get(),
            new ChequesDueAlert($toDeposit->count(), $sum($toDeposit), $issued->count(), $sum($issued), $days),
        );
    }
}
