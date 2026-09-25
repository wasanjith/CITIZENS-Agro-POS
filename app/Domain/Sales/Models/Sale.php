<?php

namespace App\Domain\Sales\Models;

use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\Catalog\Models\PriceList;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Inventory\Support\StockReference;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Policies\SalePolicy;
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
 * A bill: held (no number), invoiced at a counter (numbered, stock reserved),
 * settled at the main cashier (stock issued, money in the drawer) or void.
 *
 * @property int $id
 * @property string|null $invoice_no
 * @property SaleStatus $status
 * @property int|null $customer_id
 * @property int $price_list_id
 * @property string $cart_uuid
 * @property int $invoiced_by
 * @property int $invoiced_terminal_id
 * @property Carbon|null $invoiced_at
 * @property int|null $settled_by
 * @property int|null $settled_terminal_id
 * @property Carbon|null $settled_at
 * @property int|null $drawer_session_id
 * @property string $subtotal
 * @property string $line_discount_total
 * @property string $bill_discount
 * @property string $tax_total
 * @property string $total
 * @property PaymentMethod $payment_method_intent
 * @property string|null $tendered_amount
 * @property string $change_due
 * @property string $balance_due
 * @property string|null $cost_total
 * @property string|null $note
 * @property string|null $void_reason
 * @property int|null $voided_by
 * @property Carbon|null $voided_at
 * @property string|null $idempotency_key
 * @property string|null $settle_idempotency_key
 * @property int $print_count
 * @property Carbon $created_at
 */
#[Fillable([
    'invoice_no', 'status', 'customer_id', 'price_list_id', 'cart_uuid',
    'invoiced_by', 'invoiced_terminal_id', 'invoiced_at',
    'subtotal', 'line_discount_total', 'bill_discount', 'tax_total', 'total',
    'payment_method_intent', 'tendered_amount', 'change_due', 'balance_due', 'note',
    'idempotency_key', 'print_count',
])]
#[UsePolicy(SalePolicy::class)]
class Sale extends Model implements StockReference
{
    use LogsActivity;

    /**
     * Fields that reveal the shop's buying cost.
     */
    public const COST_FIELDS = ['cost_total'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'customer_id' => 'integer',
            'price_list_id' => 'integer',
            'invoiced_by' => 'integer',
            'invoiced_terminal_id' => 'integer',
            'invoiced_at' => 'datetime',
            'settled_by' => 'integer',
            'settled_terminal_id' => 'integer',
            'settled_at' => 'datetime',
            'drawer_session_id' => 'integer',
            'subtotal' => 'decimal:2',
            'line_discount_total' => 'decimal:2',
            'bill_discount' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'payment_method_intent' => PaymentMethod::class,
            'tendered_amount' => 'decimal:2',
            'change_due' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'cost_total' => 'decimal:2',
            'voided_by' => 'integer',
            'voided_at' => 'datetime',
            'print_count' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['invoice_no', 'status', 'total', 'bill_discount', 'payment_method_intent', 'settled_by', 'void_reason', 'voided_by'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return HasMany<SaleItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class)->orderBy('line_no');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
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
    public function invoicedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invoiced_by');
    }

    /**
     * @return BelongsTo<Terminal, $this>
     */
    public function invoicedTerminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class, 'invoiced_terminal_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    /**
     * @return BelongsTo<Terminal, $this>
     */
    public function settledTerminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class, 'settled_terminal_id');
    }

    /**
     * @return BelongsTo<DrawerSession, $this>
     */
    public function drawerSession(): BelongsTo
    {
        return $this->belongsTo(DrawerSession::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * @param  Builder<Sale>  $query
     */
    public function scopeStatus(Builder $query, SaleStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * Numbered sales (everything except held bills).
     *
     * @param  Builder<Sale>  $query
     */
    public function scopeInvoiced(Builder $query): void
    {
        $query->whereNotNull('invoice_no');
    }

    public function isInvoiced(): bool
    {
        return $this->status === SaleStatus::Invoiced;
    }

    public function isSettled(): bool
    {
        return $this->status === SaleStatus::Settled;
    }

    /**
     * Last digits people type at the cashier: "INV-2026-004512" → "4512".
     */
    public function shortNumber(): string
    {
        return ltrim((string) preg_replace('/^.*-/', '', (string) $this->invoice_no), '0') ?: '0';
    }

    /**
     * What Live Billing and the counters see about an invoice. Never contains costs.
     *
     * @return array<string, mixed>
     */
    public function liveSummary(): array
    {
        $this->loadMissing(['invoicedBy', 'invoicedTerminal', 'settledBy']);

        return [
            'id' => $this->id,
            'invoice_no' => $this->invoice_no,
            'short_no' => $this->shortNumber(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'terminal_id' => $this->invoiced_terminal_id,
            'counter_no' => $this->invoicedTerminal->counter_no,
            'terminal' => $this->invoicedTerminal->displayName(),
            'staff' => $this->invoicedBy->name,
            'total' => $this->total,
            'bill_discount' => $this->bill_discount,
            'payment_method' => $this->payment_method_intent->value,
            'payment_method_label' => $this->payment_method_intent->label(),
            'tendered' => $this->tendered_amount,
            'change_due' => $this->change_due,
            'lines' => $this->relationLoaded('items') ? $this->items->count() : null,
            'invoiced_at' => $this->invoiced_at?->toIso8601String(),
            'settled_at' => $this->settled_at?->toIso8601String(),
            'settled_by' => $this->settledBy?->name,
            'void_reason' => $this->void_reason,
            'print_url' => $this->invoice_no !== null ? route('pos.sales.invoice', $this) : null,
        ];
    }

    public function referenceLabel(): string
    {
        return $this->invoice_no ?? "Held bill #{$this->id}";
    }

    public function referenceUrl(): ?string
    {
        return route('sales.show', $this);
    }
}
