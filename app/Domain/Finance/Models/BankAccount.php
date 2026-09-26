<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\BankAccountType;
use App\Domain\Finance\Enums\BankTransactionType;
use App\Domain\Finance\Policies\BankAccountPolicy;
use App\Domain\Inventory\Support\StockReference;
use App\Domain\Sales\Support\Money;
use Brick\Math\BigDecimal;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A shop bank account with its own ledger account (15xx).
 *
 * balance = opening balance + deposits, transfers in, interest − withdrawals, transfers out, charges
 *
 * @property int $id
 * @property int $account_id
 * @property string $bank_name
 * @property string|null $branch
 * @property string $account_no
 * @property string $account_name
 * @property BankAccountType $type
 * @property string $opening_balance
 * @property Carbon $opening_date
 * @property bool $receives_card_payments
 * @property bool $is_active
 */
#[Fillable(['account_id', 'bank_name', 'branch', 'account_no', 'account_name', 'type', 'opening_balance', 'opening_date', 'receives_card_payments', 'is_active'])]
#[UsePolicy(BankAccountPolicy::class)]
class BankAccount extends Model implements StockReference
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'type' => BankAccountType::class,
            'opening_balance' => 'decimal:2',
            'opening_date' => 'date',
            'receives_card_payments' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['bank_name', 'branch', 'account_no', 'account_name', 'type', 'opening_balance', 'receives_card_payments', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @param  Builder<BankAccount>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function balance(?DateTimeInterface $asOf = null): BigDecimal
    {
        return Money::of($this->opening_balance)->plus($this->movementTotal($asOf));
    }

    /**
     * Σ signed transactions (up to $asOf).
     */
    public function movementTotal(?DateTimeInterface $asOf = null, bool $reconciledOnly = false): BigDecimal
    {
        $inflows = "'".implode("','", BankTransactionType::inflowValues())."'";

        $total = $this->transactions()
            ->when($asOf !== null, fn ($query) => $query->whereDate('date', '<=', $asOf))
            ->when($reconciledOnly, fn ($query) => $query->whereNotNull('reconciled_at'))
            ->selectRaw("COALESCE(SUM(CASE WHEN type IN ({$inflows}) THEN amount ELSE -amount END), 0) AS total")
            ->toBase()
            ->value('total');

        return Money::of((string) $total);
    }

    public function displayName(): string
    {
        return trim("{$this->bank_name} {$this->account_no}".($this->branch ? " ({$this->branch})" : ''));
    }

    public function referenceLabel(): string
    {
        return $this->displayName();
    }

    public function referenceUrl(): ?string
    {
        return route('finance.bank-accounts.show', $this);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<BankTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    /**
     * @return HasMany<BankReconciliation, $this>
     */
    public function reconciliations(): HasMany
    {
        return $this->hasMany(BankReconciliation::class);
    }

    /**
     * @return array<int, string>
     */
    public static function options(): array
    {
        return self::query()->active()->orderBy('bank_name')->get()->mapWithKeys(fn (self $bank) => [$bank->id => $bank->displayName()])->all();
    }
}
