<?php

namespace App\Domain\Sales\Models;

use App\Domain\Identity\Models\Terminal;
use App\Domain\Sales\Enums\ApprovalStatus;
use App\Domain\Sales\Enums\ApprovalType;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A counter asks the cashier to allow something (a discount above the staff limit).
 *
 * Discount payload: {scope: "line"|"bill", line_key, product_id, variant_id, label,
 *                    amount, percent, gross}
 *
 * @property int $id
 * @property ApprovalType $type
 * @property int|null $sale_id
 * @property string|null $cart_uuid
 * @property int $terminal_id
 * @property int $requested_by
 * @property array<string, mixed> $payload
 * @property ApprovalStatus $status
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property Carbon $created_at
 */
#[Fillable(['type', 'sale_id', 'cart_uuid', 'terminal_id', 'requested_by', 'payload', 'status'])]
class ApprovalRequest extends Model
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ApprovalType::class,
            'sale_id' => 'integer',
            'terminal_id' => 'integer',
            'requested_by' => 'integer',
            'payload' => 'array',
            'status' => ApprovalStatus::class,
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'payload', 'status', 'decided_by', 'decision_note'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
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
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @param  Builder<ApprovalRequest>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ApprovalStatus::Pending);
    }

    public function isApproved(): bool
    {
        return $this->status === ApprovalStatus::Approved;
    }

    /**
     * @return array<string, mixed>
     */
    public function toBroadcast(): array
    {
        $this->loadMissing(['terminal', 'requester']);

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'status' => $this->status->value,
            'cart_uuid' => $this->cart_uuid,
            'terminal_id' => $this->terminal_id,
            'terminal' => $this->terminal->displayName(),
            'requested_by' => $this->requester->name,
            'payload' => $this->payload,
            'decision_note' => $this->decision_note,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
