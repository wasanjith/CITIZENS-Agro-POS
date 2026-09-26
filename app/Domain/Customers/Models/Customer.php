<?php

namespace App\Domain\Customers\Models;

use App\Domain\Catalog\Models\PriceList;
use App\Domain\Customers\Policies\CustomerPolicy;
use App\Domain\Inventory\Support\StockReference;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Support\Money;
use Brick\Math\BigDecimal;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A regular customer (mostly farmers buying on credit until harvest). The balance is
 * the customer ledger: debits (credit sales, opening balance) − credits (payments,
 * returns to account). A negative balance is money the shop holds for the customer.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $name_si
 * @property string|null $phone
 * @property string|null $nic
 * @property string|null $address
 * @property string|null $area
 * @property int|null $price_list_id
 * @property string $credit_limit
 * @property int $credit_days
 * @property bool $is_active
 * @property string|null $notes
 * @property int|null $created_by
 */
#[Fillable(['code', 'name', 'name_si', 'phone', 'nic', 'address', 'area', 'price_list_id', 'credit_limit', 'credit_days', 'is_active', 'notes', 'created_by'])]
#[UseFactory(CustomerFactory::class)]
#[UsePolicy(CustomerPolicy::class)]
class Customer extends Model implements StockReference
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * Sale statuses that can carry an unpaid credit balance.
     *
     * @var list<SaleStatus>
     */
    public const OPEN_SALE_STATUSES = [SaleStatus::Settled, SaleStatus::PartiallyReturned, SaleStatus::Returned];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_list_id' => 'integer',
            'credit_limit' => 'decimal:2',
            'credit_days' => 'integer',
            'is_active' => 'boolean',
            'created_by' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'name_si', 'phone', 'nic', 'address', 'area', 'price_list_id', 'credit_limit', 'credit_days', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @param  Builder<Customer>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Search by phone, name (English or Sinhala), NIC, code or village.
     *
     * @param  Builder<Customer>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $digits = preg_replace('/\D+/', '', $term) ?? '';

        $query->where(function (Builder $inner) use ($term, $digits): void {
            $inner->where('name', 'like', "%{$term}%")
                ->orWhere('name_si', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%")
                ->orWhere('nic', 'like', "{$term}%")
                ->orWhere('area', 'like', "%{$term}%");

            if (strlen($digits) >= 3) {
                $inner->orWhere('phone', 'like', "%{$digits}%");
            }
        });
    }

    /**
     * What the customer owes: Σ debit − Σ credit.
     */
    public function balance(): BigDecimal
    {
        $totals = $this->ledger()->selectRaw('COALESCE(SUM(debit), 0) AS debit, COALESCE(SUM(credit), 0) AS credit')->toBase()->first();

        return Money::of((string) ($totals->debit ?? '0'))->minus(Money::of((string) ($totals->credit ?? '0')));
    }

    /**
     * How much more can go on credit before the limit is reached (never below zero).
     */
    public function availableCredit(?BigDecimal $balance = null): BigDecimal
    {
        $available = Money::of($this->credit_limit)->minus($balance ?? $this->balance());

        return $available->isNegative() ? Money::zero() : $available;
    }

    /**
     * Unpaid amount of credit invoices already past their due date.
     */
    public function overdueAmount(): BigDecimal
    {
        return Money::of((string) $this->openCreditSales()->where('due_date', '<', today())->sum('balance_due'));
    }

    /**
     * Phone number in the international format wa.me and SMS gateways expect.
     */
    public function internationalPhone(): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $this->phone) ?? '';

        if ($digits === '') {
            return null;
        }

        return str_starts_with($digits, '0') ? '94'.substr($digits, 1) : $digits;
    }

    public function referenceLabel(): string
    {
        return $this->code;
    }

    public function referenceUrl(): ?string
    {
        return route('customers.show', $this);
    }

    public function displayName(): string
    {
        return $this->name_si ? "{$this->name} ({$this->name_si})" : $this->name;
    }

    /**
     * What the POS screen needs to show about the customer.
     *
     * @return array<string, mixed>
     */
    public function posSummary(): array
    {
        $balance = $this->balance();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'name_si' => $this->name_si,
            'phone' => $this->phone,
            'area' => $this->area,
            'nic' => $this->nic,
            'price_list_id' => $this->price_list_id,
            'balance' => (string) $balance,
            'credit_limit' => (string) Money::of($this->credit_limit),
            'available_credit' => (string) $this->availableCredit($balance),
            'overdue' => (string) $this->overdueAmount(),
            'is_active' => $this->is_active,
        ];
    }

    /**
     * @return BelongsTo<PriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /**
     * @return HasMany<CustomerLedgerEntry, $this>
     */
    public function ledger(): HasMany
    {
        return $this->hasMany(CustomerLedgerEntry::class);
    }

    /**
     * @return HasMany<CustomerPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }

    /**
     * @return HasMany<Sale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * Credit invoices with money still owed on them, oldest due first.
     *
     * @return HasMany<Sale, $this>
     */
    public function openCreditSales(): HasMany
    {
        return $this->hasMany(Sale::class)
            ->whereIn('status', self::OPEN_SALE_STATUSES)
            ->where('balance_due', '>', 0)
            ->orderBy('due_date')
            ->orderBy('settled_at')
            ->orderBy('id');
    }
}
