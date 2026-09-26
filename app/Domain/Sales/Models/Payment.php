<?php

namespace App\Domain\Sales\Models;

use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\Finance\Models\BankAccount;
use App\Domain\Finance\Models\Cheque;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money received for a sale at settlement, linked to the cashier's drawer session.
 * A negative amount is a refund paid out of the drawer (void of a settled sale).
 *
 * @property int $id
 * @property int $sale_id
 * @property PaymentMethod $method
 * @property string $amount
 * @property string|null $tendered
 * @property string|null $reference
 * @property int|null $cheque_id
 * @property int|null $bank_account_id
 * @property int $recorded_by
 * @property int $confirmed_by
 * @property int $drawer_session_id
 * @property Carbon $created_at
 */
#[Fillable(['sale_id', 'method', 'amount', 'tendered', 'reference', 'cheque_id', 'bank_account_id', 'recorded_by', 'confirmed_by', 'drawer_session_id'])]
class Payment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sale_id' => 'integer',
            'method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'tendered' => 'decimal:2',
            'cheque_id' => 'integer',
            'bank_account_id' => 'integer',
            'recorded_by' => 'integer',
            'confirmed_by' => 'integer',
            'drawer_session_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return BelongsTo<Cheque, $this>
     */
    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class);
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
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
