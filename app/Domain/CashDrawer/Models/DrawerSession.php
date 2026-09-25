<?php

namespace App\Domain\CashDrawer\Models;

use App\Domain\CashDrawer\Enums\DrawerCloseReason;
use App\Domain\CashDrawer\Policies\DrawerSessionPolicy;
use App\Domain\Identity\Models\Delegation;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One holder's time with the cash drawer of a terminal: opening float → settlements,
 * pay ins/outs → count. Only one session per terminal can be open (unique index on
 * terminal_id + is_open, where is_open is NULL once closed).
 *
 * @property int $id
 * @property int $terminal_id
 * @property int $holder_user_id
 * @property Carbon $opened_at
 * @property string $opening_float
 * @property array<string, int>|null $opening_denominations
 * @property Carbon|null $closed_at
 * @property int|null $closed_by
 * @property string|null $expected_cash
 * @property string|null $counted_cash
 * @property string|null $variance
 * @property array<string, int>|null $denominations
 * @property DrawerCloseReason|null $close_reason
 * @property string|null $close_note
 * @property int|null $previous_session_id
 * @property bool|null $is_open
 */
#[Fillable(['terminal_id', 'holder_user_id', 'opened_at', 'opening_float', 'opening_denominations', 'previous_session_id', 'is_open'])]
#[UsePolicy(DrawerSessionPolicy::class)]
class DrawerSession extends Model
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'terminal_id' => 'integer',
            'holder_user_id' => 'integer',
            'opened_at' => 'datetime',
            'opening_float' => 'decimal:2',
            'opening_denominations' => 'array',
            'closed_at' => 'datetime',
            'closed_by' => 'integer',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'variance' => 'decimal:2',
            'denominations' => 'array',
            'close_reason' => DrawerCloseReason::class,
            'previous_session_id' => 'integer',
            'is_open' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['holder_user_id', 'opening_float', 'closed_at', 'expected_cash', 'counted_cash', 'variance', 'close_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return BelongsTo<Terminal, $this>
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return BelongsTo<DrawerSession, $this>
     */
    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_session_id');
    }

    /**
     * @return HasOne<DrawerSession, $this>
     */
    public function next(): HasOne
    {
        return $this->hasOne(self::class, 'previous_session_id');
    }

    /**
     * @return HasMany<CashMovement, $this>
     */
    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<Sale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * @return HasOne<Delegation, $this>
     */
    public function delegation(): HasOne
    {
        return $this->hasOne(Delegation::class);
    }

    /**
     * @param  Builder<DrawerSession>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('is_open', true);
    }

    public function isOpen(): bool
    {
        return $this->is_open === true;
    }
}
