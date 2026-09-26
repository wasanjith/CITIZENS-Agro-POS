<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\AccountType;
use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Policies\AccountPolicy;
use App\Domain\Sales\Support\Money;
use Brick\Math\BigDecimal;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An account in the chart of accounts.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property int|null $parent_id
 * @property string|null $system_key
 * @property bool $is_system
 * @property bool $is_active
 * @property string|null $description
 */
#[Fillable(['code', 'name', 'type', 'parent_id', 'system_key', 'is_system', 'is_active', 'description'])]
#[UsePolicy(AccountPolicy::class)]
class Account extends Model
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'parent_id' => 'integer',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'type', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function systemAccount(): ?SystemAccount
    {
        return $this->system_key !== null ? SystemAccount::tryFrom($this->system_key) : null;
    }

    /**
     * Balance on the account's normal side (assets and expenses: debit − credit;
     * the others: credit − debit), up to and including $asOf.
     */
    public function balance(?DateTimeInterface $asOf = null): BigDecimal
    {
        $totals = JournalLine::query()
            ->where('account_id', $this->id)
            ->when($asOf !== null, fn ($query) => $query->whereHas('entry', fn ($entry) => $entry->whereDate('date', '<=', $asOf)))
            ->selectRaw('COALESCE(SUM(debit), 0) AS debit, COALESCE(SUM(credit), 0) AS credit')
            ->toBase()
            ->first();

        $debit = Money::of((string) ($totals->debit ?? '0'));
        $credit = Money::of((string) ($totals->credit ?? '0'));

        return $this->type->isDebitNormal() ? $debit->minus($credit) : $credit->minus($debit);
    }

    public function displayName(): string
    {
        return "{$this->code} · {$this->name}";
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    /**
     * @return HasOne<BankAccount, $this>
     */
    public function bankAccount(): HasOne
    {
        return $this->hasOne(BankAccount::class);
    }
}
