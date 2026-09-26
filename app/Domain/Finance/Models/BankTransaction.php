<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\BankTransactionType;
use App\Domain\Inventory\Support\StockReference;
use App\Domain\Sales\Support\Money;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $bank_account_id
 * @property Carbon $date
 * @property BankTransactionType $type
 * @property string $amount
 * @property string|null $reference
 * @property string $description
 * @property string|null $related_type
 * @property int|null $related_id
 * @property int|null $bank_reconciliation_id
 * @property Carbon|null $reconciled_at
 * @property int|null $created_by
 * @property Carbon $created_at
 */
#[Fillable(['bank_account_id', 'date', 'type', 'amount', 'reference', 'description', 'related_type', 'related_id', 'bank_reconciliation_id', 'reconciled_at', 'created_by'])]
class BankTransaction extends Model implements StockReference
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bank_account_id' => 'integer',
            'date' => 'date',
            'type' => BankTransactionType::class,
            'amount' => 'decimal:2',
            'related_id' => 'integer',
            'bank_reconciliation_id' => 'integer',
            'reconciled_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    public function signedAmount(): BigDecimal
    {
        $amount = Money::of($this->amount);

        return $this->type->sign() > 0 ? $amount : $amount->negated();
    }

    public function referenceLabel(): string
    {
        return $this->reference ?: $this->type->label();
    }

    public function referenceUrl(): ?string
    {
        return route('finance.bank-accounts.show', $this->bank_account_id);
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
