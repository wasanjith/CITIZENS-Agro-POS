<?php

namespace App\Domain\Sales\Models;

use App\Domain\Identity\Models\Terminal;
use App\Domain\Sales\Enums\CounterEventType;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What happened at a counter (Live Billing ticker, removed-items report). Append-only,
 * pruned after 90 days.
 *
 * @property int $id
 * @property int $terminal_id
 * @property int|null $user_id
 * @property int|null $sale_id
 * @property string|null $cart_uuid
 * @property CounterEventType $type
 * @property array<string, mixed>|null $payload
 * @property Carbon $created_at
 */
#[Fillable(['terminal_id', 'user_id', 'sale_id', 'cart_uuid', 'type', 'payload', 'created_at'])]
class CounterEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'terminal_id' => 'integer',
            'user_id' => 'integer',
            'sale_id' => 'integer',
            'type' => CounterEventType::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Terminal, $this>
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * One ticker line: "10:32 removed MOP 50kg".
     */
    public function summary(): string
    {
        $payload = $this->payload ?? [];
        $what = match ($this->type) {
            CounterEventType::ItemAdded, CounterEventType::ItemRemoved => trim(($payload['qty'] ?? '').' '.($payload['unit'] ?? '').' '.($payload['name'] ?? '')),
            CounterEventType::QtyChanged => ($payload['name'] ?? '').' '.($payload['from'] ?? '').' → '.($payload['to'] ?? ''),
            CounterEventType::Tendered => 'Rs. '.number_format((float) ($payload['amount'] ?? 0), 2),
            CounterEventType::Printed, CounterEventType::Reprinted, CounterEventType::Settled, CounterEventType::Voided => (string) ($payload['invoice_no'] ?? ''),
            CounterEventType::CartCleared => ($payload['lines'] ?? 0).' lines · Rs. '.number_format((float) ($payload['total'] ?? 0), 2),
            default => '',
        };

        return trim($this->type->label().' '.$what);
    }

    /**
     * @return array<string, mixed>
     */
    public function toBroadcast(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'alert' => $this->type->isAlert(),
            'text' => $this->summary(),
            'time' => $this->created_at->format('H:i'),
            'at' => $this->created_at->toIso8601String(),
        ];
    }
}
