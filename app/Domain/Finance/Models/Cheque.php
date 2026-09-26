<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\ChequeDirection;
use App\Domain\Finance\Enums\ChequeStatus;
use App\Domain\Finance\Policies\ChequePolicy;
use App\Domain\Inventory\Support\StockReference;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A cheque received (from a customer, at settlement or as a customer payment) or issued
 * (to a supplier). source = the document it paid; party = customer / supplier.
 *
 * @property int $id
 * @property ChequeDirection $direction
 * @property string $number
 * @property string|null $bank_name
 * @property string|null $branch
 * @property Carbon $cheque_date
 * @property string $amount
 * @property string|null $party_type
 * @property int|null $party_id
 * @property string|null $party_name
 * @property int|null $bank_account_id
 * @property ChequeStatus $status
 * @property list<array{status: string, at: string, by: int|null, note: string|null}>|null $status_history
 * @property string|null $source_type
 * @property int|null $source_id
 * @property Carbon|null $deposited_on
 * @property Carbon|null $cleared_on
 * @property Carbon|null $bounced_on
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon $created_at
 */
#[Fillable(['direction', 'number', 'bank_name', 'branch', 'cheque_date', 'amount', 'party_type', 'party_id', 'party_name', 'bank_account_id', 'status', 'status_history', 'source_type', 'source_id', 'deposited_on', 'cleared_on', 'bounced_on', 'note', 'created_by'])]
#[UsePolicy(ChequePolicy::class)]
class Cheque extends Model implements StockReference
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => ChequeDirection::class,
            'cheque_date' => 'date',
            'amount' => 'decimal:2',
            'party_id' => 'integer',
            'bank_account_id' => 'integer',
            'status' => ChequeStatus::class,
            'status_history' => 'array',
            'source_id' => 'integer',
            'deposited_on' => 'date',
            'cleared_on' => 'date',
            'bounced_on' => 'date',
            'created_by' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'bank_name', 'cheque_date', 'amount', 'status', 'bank_account_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @param  Builder<Cheque>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [ChequeStatus::Pending, ChequeStatus::Deposited]);
    }

    /**
     * Change the status and remember who did it, when.
     */
    public function transition(ChequeStatus $status, ?int $userId, ?string $note = null): void
    {
        $history = $this->status_history ?? [];
        $history[] = ['status' => $status->value, 'at' => now()->toDateTimeString(), 'by' => $userId, 'note' => $note];

        $this->status = $status;
        $this->status_history = $history;
    }

    public function isPostDated(): bool
    {
        return $this->cheque_date->isFuture();
    }

    public function partyLabel(): string
    {
        return $this->party?->getAttribute('name') ?? $this->party_name ?? 'Walk-in customer';
    }

    public function referenceLabel(): string
    {
        return "Cheque {$this->number}";
    }

    public function referenceUrl(): ?string
    {
        return route('finance.cheques.show', $this);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function party(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
