<?php

namespace App\Domain\Sales\Services;

use App\Domain\Sales\Enums\CounterEventType;
use App\Domain\Sales\Models\CounterEvent;
use App\Domain\Sales\Models\Sale;

/**
 * Writes counter_events rows (Live Billing ticker and loss-prevention trail).
 */
class CounterEventRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(int $terminalId, ?int $userId, CounterEventType $type, ?string $cartUuid = null, array $payload = [], ?Sale $sale = null): CounterEvent
    {
        if ($sale !== null && $sale->invoice_no !== null) {
            $payload['invoice_no'] ??= $sale->invoice_no;
        }

        return CounterEvent::create([
            'terminal_id' => $terminalId,
            'user_id' => $userId,
            'sale_id' => $sale?->id,
            'cart_uuid' => $cartUuid ?? $sale?->cart_uuid,
            'type' => $type,
            'payload' => $payload ?: null,
            'created_at' => now(),
        ]);
    }
}
