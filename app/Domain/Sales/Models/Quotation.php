<?php

namespace App\Domain\Sales\Models;

use App\Domain\Catalog\Models\PriceList;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Sales\Enums\QuotationStatus;
use App\Domain\Sales\Policies\QuotationPolicy;
use App\Models\User;
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
 * A price offer printed from a counter cart. It reserves no stock. Loading it back on a
 * counter and printing the invoice converts it (the invoice is priced again then).
 *
 * @property int $id
 * @property string $number
 * @property QuotationStatus $status
 * @property int|null $customer_id
 * @property string|null $customer_name
 * @property int $price_list_id
 * @property Carbon $valid_until
 * @property string $subtotal
 * @property string $line_discount_total
 * @property string $bill_discount
 * @property string $tax_total
 * @property string $total
 * @property string|null $note
 * @property int|null $terminal_id
 * @property int $created_by
 * @property int|null $converted_sale_id
 * @property Carbon|null $converted_at
 * @property string|null $idempotency_key
 * @property Carbon $created_at
 */
#[Fillable([
    'number', 'status', 'customer_id', 'customer_name', 'price_list_id', 'valid_until',
    'subtotal', 'line_discount_total', 'bill_discount', 'tax_total', 'total', 'note',
    'terminal_id', 'created_by', 'idempotency_key',
])]
#[UsePolicy(QuotationPolicy::class)]
class Quotation extends Model
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'customer_id' => 'integer',
            'price_list_id' => 'integer',
            'valid_until' => 'date',
            'subtotal' => 'decimal:2',
            'line_discount_total' => 'decimal:2',
            'bill_discount' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'terminal_id' => 'integer',
            'created_by' => 'integer',
            'converted_sale_id' => 'integer',
            'converted_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'status', 'total', 'valid_until', 'converted_sale_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @param  Builder<Quotation>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->where('status', QuotationStatus::Open)->whereDate('valid_until', '>=', today());
    }

    public function isUsable(): bool
    {
        return $this->status === QuotationStatus::Open && $this->valid_until->gte(today());
    }

    /**
     * Status as the lists show it: an open quotation past its date shows as expired.
     */
    public function effectiveStatus(): QuotationStatus
    {
        return $this->status === QuotationStatus::Open && $this->valid_until->lt(today()) ? QuotationStatus::Expired : $this->status;
    }

    public function customerLabel(): ?string
    {
        return $this->customer_id !== null ? $this->customer->name : $this->customer_name;
    }

    /**
     * The quotation as a cart the counter can load.
     *
     * @return array<string, mixed>
     */
    public function toCart(): array
    {
        $this->loadMissing('lines');

        return [
            'quotation_id' => $this->id,
            'price_list_id' => $this->price_list_id,
            'customer_id' => $this->customer_id,
            'payment_method' => 'cash',
            'bill_discount' => $this->bill_discount,
            'lines' => $this->lines->map(fn (QuotationLine $line) => [
                'key' => "{$line->product_id}-".($line->variant_id ?? 0)."-{$line->unit_id}-q{$line->line_no}",
                'product_id' => $line->product_id,
                'variant_id' => $line->variant_id,
                'unit_id' => $line->unit_id,
                'qty' => $line->qty,
                'discount' => $line->discount_amount,
            ])->all(),
        ];
    }

    /**
     * @return HasMany<QuotationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class)->orderBy('line_no');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<PriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Terminal, $this>
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function convertedSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'converted_sale_id');
    }
}
