<?php

namespace App\Domain\Customers\Jobs;

use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Notifications\OverdueCreditAlert;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Support\Money;
use App\Domain\System\Services\Settings;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/**
 * Every morning: tells the Owner and Manager (bell notification) who owes money past
 * the due date. Switched off in Settings → Customers & credit. No SMS (owner's choice).
 */
class OverdueCreditReminderJob implements ShouldQueue
{
    use Queueable;

    public function handle(Settings $settings): void
    {
        if (! (bool) $settings->get('customers.overdue_alert', true)) {
            return;
        }

        $overdue = Sale::query()
            ->whereIn('status', Customer::OPEN_SALE_STATUSES)
            ->whereNotNull('customer_id')
            ->where('balance_due', '>', 0)
            ->where('due_date', '<', today())
            ->get(['id', 'customer_id', 'balance_due']);

        if ($overdue->isEmpty()) {
            return;
        }

        $total = $overdue->reduce(fn ($sum, Sale $sale) => $sum->plus($sale->balance_due), Money::zero());
        $recipients = User::permission('customers.manage')->active()->get();

        Notification::send($recipients, new OverdueCreditAlert($overdue->pluck('customer_id')->unique()->count(), (string) $total));
    }
}
