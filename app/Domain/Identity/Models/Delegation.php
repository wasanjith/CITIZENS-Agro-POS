<?php

namespace App\Domain\Identity\Models;

use App\Domain\CashDrawer\Models\DrawerSession;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\DelegationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A temporary grant of cashier-authority permissions from the owner to another user.
 *
 * @property int $id
 * @property int $from_user_id
 * @property int $to_user_id
 * @property int|null $drawer_session_id
 * @property list<string> $permissions
 * @property Carbon $starts_at
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by
 * @property Carbon|null $expiry_processed_at
 * @property string|null $reason
 */
#[Fillable(['from_user_id', 'to_user_id', 'drawer_session_id', 'permissions', 'starts_at', 'expires_at', 'reason'])]
#[UseFactory(DelegationFactory::class)]
class Delegation extends Model
{
    /** @use HasFactory<DelegationFactory> */
    use HasFactory, LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'expiry_processed_at' => 'datetime',
            'drawer_session_id' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['from_user_id', 'to_user_id', 'permissions', 'starts_at', 'expires_at', 'revoked_at', 'revoked_by', 'reason'])
            ->logOnlyDirty();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * @return BelongsTo<DrawerSession, $this>
     */
    public function drawerSession(): BelongsTo
    {
        return $this->belongsTo(DrawerSession::class);
    }

    /**
     * Delegations that are in force right now.
     *
     * @param  Builder<Delegation>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where('expires_at', '>', now());
    }

    public function isActiveAt(CarbonInterface $moment): bool
    {
        return $this->revoked_at === null
            && $this->starts_at->lessThanOrEqualTo($moment)
            && $this->expires_at->greaterThan($moment);
    }

    public function grants(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
