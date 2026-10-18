<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Support\StockReference;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
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
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
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
class Supplier extends Model implements StockReference
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
     * Folder on the local disk that holds this supplier's invoice photos and papers, named after
     * the supplier: supplier-invoices/cic-agri-businesses. Names with no Latin letters (Sinhala)
     * fall back to supplier-{id}. Papers added in Purchasing → Invoices go in sub-folders
     * (invoices, deposit-slips, receipts, other).
     */
    public function invoiceFolder(): string
    {
        return 'supplier-invoices/'.(Str::slug($this->name) ?: 'supplier-'.$this->id);
    }

    /**
     * Phone number in the international format wa.me expects (0771234567 → 94771234567).
     */
    public function referenceLabel(): string
    {
        return $this->name;
    }

    public function referenceUrl(): ?string
    {
        return route('purchasing.suppliers.show', $this);
    }

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
     * Brands bought from this supplier.
     *
     * @return HasMany<Brand, $this>
     */
    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
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
     * Posted goods receipts not fully paid, oldest first.
     *
     * @return HasMany<GoodsReceipt, $this>
     */
    public function unpaidReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class)
            ->where('status', GoodsReceiptStatus::Posted)
            ->whereColumn('amount_paid', '<', 'total')
            ->orderBy('received_at')
            ->orderBy('id');
    }

    /**
     * @return HasMany<SupplierPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    /**
     * @return HasMany<SupplierReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(SupplierReturn::class);
    }

    /**
     * Invoices, deposit slips, receipts … in the supplier's file (Purchasing → Invoices).
     *
     * @return HasMany<SupplierDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(SupplierDocument::class);
    }

    /**
     * Invoice photos attached to this supplier's goods receipts.
     *
     * @return HasManyThrough<GoodsReceiptInvoice, GoodsReceipt, $this>
     */
    public function goodsReceiptInvoices(): HasManyThrough
    {
        return $this->hasManyThrough(GoodsReceiptInvoice::class, GoodsReceipt::class);
    }

    /**
     * @return HasMany<SupplierLedgerEntry, $this>
     */
    public function ledger(): HasMany
    {
        return $this->hasMany(SupplierLedgerEntry::class);
    }
}
