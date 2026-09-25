<?php

namespace App\Domain\CashDrawer\Models;

use App\Domain\CashDrawer\Enums\CashMovementType;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Cash put into or taken out of the drawer outside a sale. amount is always positive;
 * the type gives the direction.
 *
 * @property int $id
 * @property int $drawer_session_id
 * @property CashMovementType $type
 * @property string $amount
 * @property string $reason
 * @property int $user_id
 * @property Carbon $created_at
 */
#[Fillable(['drawer_session_id', 'type', 'amount', 'reason', 'user_id'])]
class CashMovement extends Model
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'drawer_session_id' => 'integer',
            'type' => CashMovementType::class,
            'amount' => 'decimal:2',
            'user_id' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['drawer_session_id', 'type', 'amount', 'reason']);
    }

    /**
     * @return BelongsTo<DrawerSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(DrawerSession::class, 'drawer_session_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
