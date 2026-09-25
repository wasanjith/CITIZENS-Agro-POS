<?php

namespace App\Domain\Inventory\Jobs;

use App\Domain\Inventory\Notifications\LowStockAndExpiryAlert;
use App\Domain\Inventory\Services\StockAlerts;
use App\Domain\System\Services\Settings;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/**
 * Daily at 07:00: tells the Owner and Manager how many products are at or below their
 * reorder level and how many batches expire soon. Nothing is sent when all is well.
 */
class LowStockAndExpiryAlertJob implements ShouldQueue
{
    use Queueable;

    public function handle(StockAlerts $alerts, Settings $settings): void
    {
        $days = (int) $settings->get('inventory.expiry_alert_days', 30);
        $lowStock = $alerts->lowStockCount();
        $expiring = $alerts->expiringCount($days);

        if ($lowStock === 0 && $expiring === 0) {
            return;
        }

        $recipients = User::permission('reports.inventory')->active()->get();

        Notification::send($recipients, new LowStockAndExpiryAlert($lowStock, $expiring, $days));
    }
}
