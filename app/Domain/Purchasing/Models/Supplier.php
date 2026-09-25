<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Purchasing\Policies\SupplierPolicy;
use Brick\Math\BigDecimal;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $name
 * @property string|null $contact_person
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property int $payment_terms_days
 * @property string $opening_balance
 * @property bool $is_active
 */
#[Fillable(['name', 'contact_person', 'phone', 'email', 'address', 'payment_terms_days', 'opening_balance', 'is_active'])]
#[UseFactory(SupplierFactory::class)]
#[UsePolicy(SupplierPolicy::class)]
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_terms_days' => 'integer',
            'opening_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'contact_person', 'phone', 'email', 'address', 'payment_terms_days', 'opening_balance', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @param  Builder<Supplier>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * What the shop owes this supplier: opening balance + credits − debits.
     */
    public function balance(): BigDecimal
    {
        $totals = $this->ledger()->selectRaw('COALESCE(SUM(credit), 0) AS credit, COALESCE(SUM(debit), 0) AS debit')->toBase()->first();

        return BigDecimal::of($this->opening_balance)
            ->plus((string) ($totals->credit ?? '0'))
            ->minus((string) ($totals->debit ?? '0'));
    }

    /**
     * Phone number in the international format wa.me expects (0771234567 → 94771234567).
     */
    public function whatsappNumber(): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $this->phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            return '94'.substr($digits, 1);
        }

        return $digits;
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'supplier_products')
            ->withPivot(['supplier_code', 'last_cost', 'lead_days'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<PurchaseOrder, $this>
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /**
     * @return HasMany<GoodsReceipt, $this>
     */
    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    /**
     * @return HasMany<SupplierReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(SupplierReturn::class);
    }

    /**
     * @return HasMany<SupplierLedgerEntry, $this>
     */
    public function ledger(): HasMany
    {
        return $this->hasMany(SupplierLedgerEntry::class);
    }
}
