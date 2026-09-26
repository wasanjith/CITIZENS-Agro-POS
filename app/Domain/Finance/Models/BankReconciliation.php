<?php

namespace App\Domain\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A bank statement checked against the bank book: the ticked transactions got
 * reconciled_at; cleared balance = opening balance + every reconciled transaction.
 *
 * @property int $id
 * @property int $bank_account_id
 * @property Carbon $statement_date
 * @property string $statement_balance
 * @property string $cleared_balance
 * @property string $difference
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon $created_at
 */
#[Fillable(['bank_account_id', 'statement_date', 'statement_balance', 'cleared_balance', 'difference', 'note', 'created_by'])]
class BankReconciliation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bank_account_id' => 'integer',
            'statement_date' => 'date',
            'statement_balance' => 'decimal:2',
            'cleared_balance' => 'decimal:2',
            'difference' => 'decimal:2',
            'created_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return HasMany<BankTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
